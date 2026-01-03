# Secure Server Deployment

This guide sets up PocketDev on a VPS with Tailscale-only access. After setup, your server is invisible to the public internet - only your devices can reach it.

**Time required**: ~15 minutes

---

## Prerequisites

- A VPS provider account (Hetzner, DigitalOcean, Linode, etc.)
- Tailscale account (free at [tailscale.com](https://tailscale.com))
- Tailscale app installed on your phone/desktop

---

## Step 1: Create VPS

Using Hetzner as example (similar for other providers):

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

Choose a password when prompted.

---

## Step 4: Install Tailscale

```bash
# Install Tailscale
curl -fsSL https://tailscale.com/install.sh | sh

# Start Tailscale with SSH enabled
sudo tailscale up --ssh
```

A URL will appear - open it in your browser to authenticate.

After authenticating, get your Tailscale IP:

```bash
tailscale ip -4
# Example output: 100.64.0.5
```

**Write down this IP** - you'll use it from now on.

---

## Step 5: Switch to Tailscale Connection

```bash
exit
```

From your local machine, connect via Tailscale:

```bash
ssh pocketdev@<tailscale-ip>
```

This works without a password because Tailscale SSH handles authentication.

---

## Step 6: Run Security Setup Script

```bash
# Download the setup script
curl -O https://raw.githubusercontent.com/YOUR_USERNAME/pocket-dev/main/server-setup.sh
chmod +x server-setup.sh

# Run it
sudo ./server-setup.sh
```

This script:
- Updates the system
- Enables automatic security updates
- Installs Docker with log rotation
- Configures iptables to block ALL public access
- Only allows connections through Tailscale

---

## Step 7: Deploy PocketDev

Log out and back in (required for Docker permissions):

```bash
exit
ssh pocketdev@<tailscale-ip>
```

Clone and start PocketDev:

```bash
cd ~/apps
git clone https://github.com/YOUR_USERNAME/pocket-dev.git
cd pocket-dev

# Configure environment
cp .env.example .env
nano .env  # Edit settings as needed

# Start containers
docker compose up -d
```

---

## Step 8: Access PocketDev

Install the Tailscale app on your phone/other devices and log in with the same account.

Then access PocketDev at:

```
http://<tailscale-ip>
```

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

## Troubleshooting

### Can't connect via Tailscale IP

1. Check Tailscale is running on both devices: `tailscale status`
2. Ensure both devices are logged into the same Tailscale account
3. Check the Tailscale admin console for device status

### Locked out of server

If you ran the setup script while connected via public IP:

1. Access via Hetzner/provider's web console
2. Run: `sudo iptables -I INPUT -p tcp --dport 22 -j ACCEPT`
3. Reconnect and fix Tailscale setup

### Docker permission denied

Log out and back in after running the setup script:

```bash
exit
ssh pocketdev@<tailscale-ip>
```

---

## Optional: Custom Domain

You can use Tailscale's MagicDNS for a friendly name:

```
http://pocketdev-prod01.<tailnet-name>.ts.net
```

Or set up a custom domain in the Tailscale admin console.
