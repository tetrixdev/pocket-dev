# Secure Server Deployment

This guide sets up PocketDev on a VPS with Tailscale-only access. After setup, your server is invisible to the public internet - only your devices can reach it.

**Time required**: ~15 minutes

---

## Deployment Options

After securing the server (Steps 1-7), you have two options for deploying PocketDev:

| Option | For | Method |
|--------|-----|--------|
| **Standard Install** | Most users | Uses pre-built images, simple setup |
| **From Source** | Contributors/developers | Clone repo, full source access |

This guide covers both - follow the standard install unless you want to modify the code.

---

## Prerequisites

- A VPS provider account (DigitalOcean, Linode, Vultr, etc.)
- Tailscale account (free at [tailscale.com](https://tailscale.com))

---

## Step 1: Create VPS

Example settings (similar across providers):

| Setting | Value |
|---------|-------|
| OS | Ubuntu 24.04 LTS |
| Type | CX22 (2 vCPU, 4GB RAM) or smallest |
| Location | Closest to you |
| SSH Key | Skip (use password) |
| Firewall | Skip (we use iptables) |
| Backups | Optional |

Save the root password from the confirmation email.

---

## Step 2: Initial Server Access

This is the only time you'll use the public IP:

```bash
ssh root@<public-ip>
```

Enter password from email. You'll be prompted to change it.

---

## Step 3: Create Non-Root User

```bash
adduser pocketdev
usermod -aG sudo pocketdev
```

When prompted for Full Name, Room Number, etc. - just press Enter to skip. Only the password matters.

---

## Step 4: Install Tailscale on Server

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up --ssh
```

A URL will appear - open it in your browser to authenticate with your Tailscale account.

After authenticating, get your Tailscale IP:

```bash
tailscale ip -4
# Example output: 100.64.0.5
```

**Write down this IP** - you'll use it from now on.

---

## Step 5: Install Tailscale on Your Devices

Before you can connect via Tailscale, install it on your local machine:

**Desktop:**
1. Go to [tailscale.com/download](https://tailscale.com/download)
2. Download and install for your OS (Windows/Mac/Linux)
3. Open Tailscale and sign in with the same account you used on the server

**Mobile:**
1. Install "Tailscale" from App Store / Play Store
2. Sign in with the same account

Once signed in, all your devices can see each other via Tailscale IPs (100.x.x.x).

---

## Step 6: Switch to Tailscale Connection

Disconnect from the public IP:

```bash
exit
```

From your local machine, connect via Tailscale:

```bash
ssh pocketdev@<tailscale-ip>
```

This should work without a password because Tailscale SSH handles authentication.

---

## Step 7: Run Security Setup Script

```bash
curl -O https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/server-setup.sh
chmod +x server-setup.sh
sudo ./server-setup.sh
```

Type `yes` when prompted to confirm.

This script:
- Updates the system and installs git, nano
- Enables automatic security updates
- Installs Docker with log rotation
- Configures iptables to block ALL public access
- Creates a 2GB swap file
- Only allows connections through Tailscale

---

## Step 8: Deploy PocketDev

Log out and back in (required for Docker group permissions):

```bash
exit
ssh pocketdev@<tailscale-ip>
```

### Option A: Standard Install (Recommended)

For most users - uses pre-built Docker images:

```bash
mkdir pocket-dev && cd pocket-dev
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/setup.sh -o setup.sh
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/compose.yml -o compose.yml
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/.env.example -o .env.example
chmod +x setup.sh && ./setup.sh
```

### Option B: From Source (For Contributors)

If you want to modify the code or contribute:

```bash
mkdir -p ~/docker-apps && cd ~/docker-apps
git clone https://github.com/tetrixdev/pocket-dev.git
cd pocket-dev

# Configure environment
cp .env.example .env
nano .env  # Edit settings as needed

# Start containers
docker compose up -d
```

---

## Step 9: Access PocketDev

Open in your browser:

```
http://<tailscale-ip>
```

This works from any device where you've installed and signed into Tailscale.

---

## Security Summary

After setup, your server has:

| Layer | Protection |
|-------|------------|
| **Network** | All ports blocked except Tailscale |
| **SSH** | Only via Tailscale (no public SSH) |
| **Docker** | Containers only accessible via Tailscale |
| **Updates** | Automatic security patches |

The public IP returns nothing - not even a connection refused. The server is invisible to port scanners.

---

## Optional: Use a Custom Domain

Instead of remembering the Tailscale IP, you can point a subdomain to it:

1. In your DNS provider, add an A record:
   ```
   pocketdev.yourdomain.com → 100.64.x.x
   ```

2. Access via `http://pocketdev.yourdomain.com`

The DNS is public, but only Tailscale devices can actually connect to that IP.

Alternatively, Tailscale provides a free MagicDNS name:
```
http://<server-hostname>.<tailnet-name>.ts.net
```

---

## Troubleshooting

### Can't connect via Tailscale IP

1. Check Tailscale is running on both devices: `tailscale status`
2. Ensure both devices are logged into the same Tailscale account
3. Check the [Tailscale admin console](https://login.tailscale.com/admin/machines) for device status

### Locked out of server

If you ran the setup script while connected via public IP:

1. Access via your provider's web console (VNC/Console option)
2. Run: `sudo iptables -I INPUT -p tcp --dport 22 -j ACCEPT`
3. Reconnect via public IP and fix Tailscale setup

### Docker permission denied

Log out and back in after running the setup script:

```bash
exit
ssh pocketdev@<tailscale-ip>
```

### Script fails on non-Ubuntu

The script supports Ubuntu and Debian. For other distributions, you'll need to adapt the package installation commands.
