#!/usr/bin/env python3
import json
import sys
import os

# Read all findings
with open('/pocketdev-source/.review-tmp/all-findings-merged.json', 'r') as f:
    data = json.load(f)

groups = {
    1: ['BL-001', 'BL-007', 'SEC-001', 'SEC-008', 'UX-006', 'ARCH-004', 'CONS-005'],
    2: ['BL-002', 'BL-005', 'SEC-004', 'ARCH-005', 'EFF-007'],
    3: ['BL-003', 'BL-004', 'SEC-002', 'SEC-005', 'CONS-007', 'EFF-001'],
    4: ['BL-006', 'SEC-007', 'UX-003', 'UX-007', 'ARCH-002', 'CONS-004', 'EFF-004', 'EFF-008'],
    5: ['UX-001', 'UX-002', 'UX-004', 'UX-005', 'UX-008', 'ARCH-003', 'ARCH-006', 'CONS-006', 'EFF-002', 'EFF-003', 'EFF-006', 'EFF-009'],
    6: ['CONS-001', 'CONS-002', 'CONS-003', 'SEC-003', 'SEC-006', 'ARCH-001', 'ARCH-007', 'EFF-005'],
}

output_dir = '/pocketdev-source/.review-tmp/phase3-groups'
os.makedirs(output_dir, exist_ok=True)

for group_num, ids in groups.items():
    result = [f for f in data if f['id'] in ids]
    output_path = os.path.join(output_dir, f'group-{group_num}.json')
    with open(output_path, 'w') as f:
        json.dump(result, f, indent=2)
    print(f"Group {group_num}: {len(result)} findings written to {output_path}")

# Verify total
total = sum(len([f for f in data if f['id'] in ids]) for ids in groups.values())
print(f"\nTotal findings across all groups: {total}")
print(f"Total findings in source: {len(data)}")
