#!/usr/bin/env python3
"""
Create an Outlook .msg file from JSON input.

Reads JSON from stdin with message data (from Microsoft Graph API format)
and writes a valid .msg file to the specified output path.

Usage:
    echo '{"subject":"Test","output_path":"/tmp/test.msg",...}' | python3 create_msg.py

The MSG format is an OLE2 compound document containing MAPI properties.
Reference: [MS-OXMSG] https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxmsg
"""

import json
import struct
import sys
import base64
from datetime import datetime, timezone
from extract_msg import OleWriter


# === MAPI Property IDs ===
PR_MESSAGE_CLASS = 0x001A
PR_SUBJECT = 0x0037
PR_CLIENT_SUBMIT_TIME = 0x0039
PR_SENT_REPRESENTING_NAME = 0x0042
PR_SENT_REPRESENTING_ADDRTYPE = 0x0064
PR_SENT_REPRESENTING_EMAIL = 0x0065
PR_CONVERSATION_TOPIC = 0x0070
PR_SENDER_NAME = 0x0C1A
PR_SENDER_ADDRTYPE = 0x0C1E
PR_SENDER_EMAIL = 0x0C1F
PR_IMPORTANCE = 0x0017
PR_SENSITIVITY = 0x0036
PR_MESSAGE_DELIVERY_TIME = 0x0E06
PR_MESSAGE_FLAGS = 0x0E07
PR_NORMALIZED_SUBJECT = 0x0E1D
PR_BODY = 0x1000
PR_BODY_HTML = 0x1013
PR_INTERNET_MESSAGE_ID = 0x1035

# Recipient properties
PR_RECIPIENT_TYPE = 0x0C15
PR_RESPONSIBILITY = 0x0E0F
PR_DISPLAY_NAME = 0x3001
PR_ADDRTYPE = 0x3002
PR_EMAIL_ADDRESS = 0x3003
PR_SMTP_ADDRESS = 0x39FE
PR_DISPLAY_NAME_W = 0x3A20

# Attachment properties
PR_ATTACH_NUM = 0x0E21
PR_ATTACH_METHOD = 0x3705
PR_ATTACH_FILENAME = 0x3704
PR_ATTACH_LONG_FILENAME = 0x3707
PR_ATTACH_MIME_TAG = 0x370E
PR_ATTACH_DATA_BIN = 0x3701
PR_ATTACH_SIZE = 0x0E20
PR_ATTACH_EXTENSION = 0x3703

# Property types
PT_LONG = 0x0003
PT_BOOLEAN = 0x000B
PT_SYSTIME = 0x0040
PT_UNICODE = 0x001F
PT_BINARY = 0x0102

# FILETIME epoch: Jan 1, 1601
FILETIME_EPOCH = datetime(1601, 1, 1, tzinfo=timezone.utc)


def datetime_to_filetime(dt):
    """Convert a datetime to a Windows FILETIME (100ns intervals since 1601-01-01)."""
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    delta = dt - FILETIME_EPOCH
    return int(delta.total_seconds() * 10_000_000)


def parse_datetime(s):
    """Parse an ISO 8601 datetime string (from Graph API)."""
    if not s:
        return datetime.now(timezone.utc)
    s = s.rstrip('Z').split('.')[0]
    try:
        return datetime.strptime(s, '%Y-%m-%dT%H:%M:%S').replace(tzinfo=timezone.utc)
    except ValueError:
        return datetime.now(timezone.utc)


def encode_unicode(text):
    """Encode a string as UTF-16-LE for MSG storage."""
    return text.encode('utf-16-le')


def build_properties_stream(properties, header_size=32, recipient_count=0, attachment_count=0):
    """
    Build a __properties_version1.0 stream.

    Root message: 32-byte header.
    Recipients/attachments: 8-byte header.

    Each property entry is 16 bytes:
      type(2) + id(2) + flags(4) + value(8)
    """
    if header_size == 32:
        header = struct.pack('<8sIIII8s',
                             b'\x00' * 8,
                             recipient_count,    # next_recipient_id
                             attachment_count,    # next_attachment_id
                             recipient_count,
                             attachment_count,
                             b'\x00' * 8)
    else:
        header = b'\x00' * 8

    entries = b''
    for prop_id, prop_type, flags, value in properties:
        if prop_type == PT_LONG:
            val_bytes = struct.pack('<I', value) + b'\x00' * 4
        elif prop_type == PT_BOOLEAN:
            val_bytes = struct.pack('<I', 1 if value else 0) + b'\x00' * 4
        elif prop_type == PT_SYSTIME:
            val_bytes = struct.pack('<Q', value)
        elif prop_type in (PT_UNICODE, PT_BINARY):
            # Variable-length: store size of stream data
            val_bytes = struct.pack('<I', value) + b'\x00' * 4
        else:
            val_bytes = b'\x00' * 8

        entries += struct.pack('<HHI', prop_type, prop_id, flags) + val_bytes

    return header + entries


def create_msg(data, output_path):
    """Create a .msg file from message data dict."""
    writer = OleWriter()

    subject = data.get('subject', '(No Subject)')
    from_name = data.get('from_name', '')
    from_email = data.get('from_email', '')
    to_recipients = data.get('to_recipients', [])
    cc_recipients = data.get('cc_recipients', [])
    body_text = data.get('body_text', '')
    body_html = data.get('body_html', '')
    received_dt = parse_datetime(data.get('received_date', ''))
    sent_dt = parse_datetime(data.get('sent_date', ''))
    msg_id = data.get('internet_message_id', '')
    attachments = data.get('attachments', [])

    importance_map = {'low': 0, 'normal': 1, 'high': 2}
    importance = importance_map.get(data.get('importance', 'normal'), 1)

    # --- String streams (root level) ---
    string_props = {
        PR_MESSAGE_CLASS: 'IPM.Note',
        PR_SUBJECT: subject,
        PR_CONVERSATION_TOPIC: subject,
        PR_NORMALIZED_SUBJECT: subject,
        PR_SENT_REPRESENTING_NAME: from_name,
        PR_SENT_REPRESENTING_ADDRTYPE: 'SMTP',
        PR_SENT_REPRESENTING_EMAIL: from_email,
        PR_SENDER_NAME: from_name,
        PR_SENDER_ADDRTYPE: 'SMTP',
        PR_SENDER_EMAIL: from_email,
        PR_BODY: body_text,
    }

    if msg_id:
        string_props[PR_INTERNET_MESSAGE_ID] = msg_id

    root_properties = []

    for prop_id, text in string_props.items():
        encoded = encode_unicode(text)
        stream_name = f'__substg1.0_{prop_id:04X}001F'
        writer.addEntry(stream_name, encoded)
        root_properties.append((prop_id, PT_UNICODE, 0x00000006, len(encoded)))

    # HTML body as binary stream (UTF-8 encoded)
    if body_html:
        html_bytes = body_html.encode('utf-8')
        writer.addEntry(f'__substg1.0_{PR_BODY_HTML:04X}0102', html_bytes)
        root_properties.append((PR_BODY_HTML, PT_BINARY, 0x00000006, len(html_bytes)))

    # --- Fixed properties ---
    root_properties.append((PR_CLIENT_SUBMIT_TIME, PT_SYSTIME, 0x00000006, datetime_to_filetime(sent_dt)))
    root_properties.append((PR_MESSAGE_DELIVERY_TIME, PT_SYSTIME, 0x00000006, datetime_to_filetime(received_dt)))
    root_properties.append((PR_IMPORTANCE, PT_LONG, 0x00000006, importance))
    root_properties.append((PR_SENSITIVITY, PT_LONG, 0x00000006, 0))
    root_properties.append((PR_MESSAGE_FLAGS, PT_LONG, 0x00000006, 0x01))  # MSGFLAG_READ

    # --- Recipients ---
    all_recipients = []
    for r in to_recipients:
        all_recipients.append((r, 1))  # MAPI_TO
    for r in cc_recipients:
        all_recipients.append((r, 2))  # MAPI_CC

    for idx, (recip, recip_type) in enumerate(all_recipients):
        recip_name = recip.get('name', recip.get('email', ''))
        recip_email = recip.get('email', '')
        prefix = f'__recip_version1.0_#{idx:08X}'

        writer.addEntry(prefix, storage=True)

        recip_strings = {
            PR_DISPLAY_NAME: recip_name,
            PR_ADDRTYPE: 'SMTP',
            PR_EMAIL_ADDRESS: recip_email,
            PR_SMTP_ADDRESS: recip_email,
            PR_DISPLAY_NAME_W: recip_name,
        }

        recip_props = []
        for prop_id, text in recip_strings.items():
            encoded = encode_unicode(text)
            writer.addEntry(f'{prefix}/__substg1.0_{prop_id:04X}001F', encoded)
            recip_props.append((prop_id, PT_UNICODE, 0x00000006, len(encoded)))

        recip_props.append((PR_RECIPIENT_TYPE, PT_LONG, 0x00000006, recip_type))
        recip_props.append((PR_RESPONSIBILITY, PT_BOOLEAN, 0x00000006, True))

        recip_props_stream = build_properties_stream(recip_props, header_size=8)
        writer.addEntry(f'{prefix}/__properties_version1.0', recip_props_stream)

    # --- Attachments ---
    non_inline = [a for a in attachments if not a.get('isInline', False)]

    for idx, att in enumerate(non_inline):
        att_name = att.get('name', f'attachment_{idx}')
        att_mime = att.get('contentType', 'application/octet-stream')
        att_data = base64.b64decode(att.get('contentBytes', '')) if att.get('contentBytes') else b''
        prefix = f'__attach_version1.0_#{idx:08X}'

        writer.addEntry(prefix, storage=True)

        # Binary attachment data
        writer.addEntry(f'{prefix}/__substg1.0_{PR_ATTACH_DATA_BIN:04X}0102', att_data)

        # Short filename (8.3 format)
        if '.' in att_name:
            name_part, ext_part = att_name.rsplit('.', 1)
            short_name = name_part[:8] + '.' + ext_part[:3]
        else:
            short_name = att_name[:12]

        att_strings = {
            PR_ATTACH_FILENAME: short_name,
            PR_ATTACH_LONG_FILENAME: att_name,
            PR_ATTACH_MIME_TAG: att_mime,
        }

        if '.' in att_name:
            att_strings[PR_ATTACH_EXTENSION] = '.' + att_name.rsplit('.', 1)[-1]

        att_props = []
        att_props.append((PR_ATTACH_DATA_BIN, PT_BINARY, 0x00000006, len(att_data)))

        for prop_id, text in att_strings.items():
            encoded = encode_unicode(text)
            writer.addEntry(f'{prefix}/__substg1.0_{prop_id:04X}001F', encoded)
            att_props.append((prop_id, PT_UNICODE, 0x00000006, len(encoded)))

        att_props.append((PR_ATTACH_NUM, PT_LONG, 0x00000006, idx))
        att_props.append((PR_ATTACH_METHOD, PT_LONG, 0x00000006, 1))  # ATTACH_BY_VALUE
        att_props.append((PR_ATTACH_SIZE, PT_LONG, 0x00000006, len(att_data)))

        att_props_stream = build_properties_stream(att_props, header_size=8)
        writer.addEntry(f'{prefix}/__properties_version1.0', att_props_stream)

    # --- Named Properties storage (required by MS-OXMSG spec) ---
    # This storage maps named MAPI properties to IDs. Even if we don't use
    # named properties, the storage and its GUID stream must exist for
    # parsers like extract-msg to handle attachments correctly.
    writer.addEntry('__nameid_version1.0', storage=True)
    writer.addEntry('__nameid_version1.0/__substg1.0_00020102', b'')  # GUID stream (empty)
    writer.addEntry('__nameid_version1.0/__substg1.0_00030102', b'')  # Entry stream (empty)
    writer.addEntry('__nameid_version1.0/__substg1.0_00040102', b'')  # String stream (empty)

    # --- Build root properties stream ---
    root_props_stream = build_properties_stream(
        root_properties,
        header_size=32,
        recipient_count=len(all_recipients),
        attachment_count=len(non_inline)
    )
    writer.addEntry('__properties_version1.0', root_props_stream)

    # --- Write the OLE compound file ---
    with open(output_path, 'wb') as f:
        writer.write(f)


def main():
    try:
        data = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        print(json.dumps({'error': f'Invalid JSON input: {e}'}))
        sys.exit(1)

    output_path = data.get('output_path')
    if not output_path:
        print(json.dumps({'error': 'output_path is required'}))
        sys.exit(1)

    try:
        create_msg(data, output_path)
        print(json.dumps({'success': True, 'path': output_path}))
    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)


if __name__ == '__main__':
    main()
