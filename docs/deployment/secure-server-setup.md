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

- A VPS provider account (Hetzner, DigitalOcean, Linode, Vultr, etc.)
- Tailscale account (free at [tailscale.com](https://tailscale.com))

---

## Step 1: Create VPS

Example settings (similar across providers):

| Setting | Value |
|---------|-------|
| OS | Ubuntu 24.04 LTS |
| Minimum specs | 2 vCPU, 4GB RAM |
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

Enter password from email. You'll be prompted to change it. Store the new one.

---

## Step 3: Install Tailscale on Server

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up --ssh
tailscale ip -4
# Example output: 100.64.0.5
```

A URL will appear - open it in your browser to authenticate with your Tailscale account.

After authenticating, it should echo your Tailscale IP

**Write down this IP** - you'll use it from now on. (Or don't. You can also find this on your phone/PC after connecting those)

---

## Step 4: Install Tailscale on Your Devices (If not already using Tailscale)

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

## Step 5: Switch to Tailscale Connection

Disconnect from the public IP:

```bash
exit
```

From your local machine, connect via Tailscale:

```bash
ssh root@<tailscale-ip>
```

This should work without a password because Tailscale SSH handles authentication.

---

## Step 6: Run Security Setup Script

```bash
curl -O https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/server-setup.sh
chmod +x server-setup.sh
sudo ./server-setup.sh
rm ./server-setup.sh
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

## Step 7: Deploy PocketDev

Log out and back in (required for Docker group permissions):

```bash
exit
ssh root@<tailscale-ip>
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
# Clone the repository
git clone https://github.com/tetrixdev/pocket-dev.git
cd pocket-dev

# Run setup (auto-detects USER_ID, GROUP_ID, DOCKER_GID)
./setup.sh

# Start development environment
docker compose up -d
```

---

## Step 8: Enable HTTPS (Required for Voice Input)

Voice input on mobile requires HTTPS. Tailscale provides free automatic SSL certificates for your `.ts.net` domain.

> **Important:** Enabling HTTPS publishes your machine name and tailnet name to a public certificate transparency ledger. If your machine or tailnet names contain sensitive information, rename them first.

For full details, see [Tailscale HTTPS documentation](https://tailscale.com/kb/1153/enabling-https).

**1. (Optional) Rename your tailnet:**

Your tailnet name (e.g., `tail1234.ts.net`) will be public. To rename it:
- Go to [Tailscale Admin Console → DNS](https://login.tailscale.com/admin/dns)
- Click **Rename tailnet...** at the top

Do this **before** enabling HTTPS - changing it later requires re-provisioning certificates.

**2. Enable HTTPS certificates:**

In the same DNS settings page, under **HTTPS Certificates**, click **Enable HTTPS**.

**3. Get your server's hostname:**

```bash
tailscale status
```

Note your server's machine name. You can also rename it in the [Machines page](https://login.tailscale.com/admin/machines) if needed.

**4. Enable Tailscale Serve to proxy HTTPS:**

```bash
tailscale serve --bg http://localhost:80
```

This makes Tailscale handle HTTPS termination and forward requests to your HTTP containers. Verify it's running:

```bash
tailscale serve status
```

The `--bg` flag runs it persistently in the background, surviving reboots.

**5. Update your `.env` file:**

```bash
nano .env
```

Update `APP_URL` and enable `FORCE_HTTPS`:
```text
APP_URL=https://<server-name>.<tailnet-name>.ts.net
FORCE_HTTPS=true
```

**6. Clear config cache and restart:**

```bash
docker compose exec pocket-dev-php php artisan config:cache
docker compose restart
```

---

## Step 9: Access PocketDev

Open in your browser (using the hostname from Step 8):

```text
https://<server-name>.<tailnet-name>.ts.net
```

For example: `https://pocketdev-prod01.tail1234.ts.net`

This works from any device where you've installed and signed into Tailscale. Voice input will now work on mobile.

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

If you prefer your own domain instead of the `.ts.net` MagicDNS name:

1. In your DNS provider, add an A record pointing to your Tailscale IP:
   ```text
   pocketdev.yourdomain.com → 100.64.x.x
   ```

2. Update `APP_URL` in your `.env` file

3. For HTTPS with a custom domain, you'll need to set up your own SSL certificates (e.g., with Caddy or Let's Encrypt)

Note: The DNS record is public, but only Tailscale devices can actually connect to that IP.

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
ssh root@<tailscale-ip>
```

### Script fails on non-Ubuntu

The script supports Ubuntu and Debian. For other distributions, you'll need to adapt the package installation commands.
