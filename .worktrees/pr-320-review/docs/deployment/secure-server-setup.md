# Secure Server Deployment

This guide sets up PocketDev on a VPS with Tailscale-only access. After setup, your server is invisible to the public internet - only your devices can reach it.

**Time required**: ~15 minutes

---

## Deployment Options

After securing the server (Steps 1-4), you have two options for deploying PocketDev:

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

## Step 2: Connect and Complete Server Setup

SSH to your server using the public IP:

```bash
ssh root@<public-ip>
```

Enter password from email. You'll be prompted to change it. Store the new one.

Now run all the server setup commands in one session:

**1. Install Tailscale:**

```bash
curl -fsSL https://tailscale.com/install.sh | sh
tailscale up --ssh
```

A URL will appear - open it in your browser to authenticate. After authenticating:

```bash
tailscale ip -4
# Note this IP (e.g., 100.64.0.5) - you'll use it later
```

**2. Run the security setup script:**

```bash
curl -O https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/server-setup.sh
chmod +x server-setup.sh
./server-setup.sh
rm ./server-setup.sh
```

Type `yes` when prompted. This script:
- Updates the system and installs git, nano
- Enables automatic security updates
- Installs Docker with log rotation
- Configures iptables to block ALL public access
- Creates a 2GB swap file
- Only allows connections through Tailscale

> **Note:** After this runs, public IP access is blocked - but your current session stays alive (established connections are preserved). If your connection drops for any reason, just reconnect via Tailscale IP instead.

**3. Enable Tailscale HTTPS (Recommended):**

Voice input on mobile requires HTTPS.

First, in your browser:
- Go to [Tailscale Admin Console → DNS](https://login.tailscale.com/admin/dns)
- Enable **MagicDNS** if not already enabled
- (Optional) Click **Rename tailnet...** if you want a custom name
- Under **HTTPS Certificates**, click **Enable HTTPS**

Then back in the terminal:

```bash
tailscale status  # Note your machine name
tailscale cert <machine-name>.<tailnet-name>.ts.net  # Provision TLS certificate
tailscale serve --bg http://localhost:80
tailscale serve status
```

**4. Create non-root user:**

PocketDev must run as a non-root user (Claude CLI refuses root for security).

```bash
useradd -m -s /bin/bash pocketdev
usermod -aG docker pocketdev
```

**5. Exit and reconnect via Tailscale:**

```bash
exit
```

---

## Step 3: Install Tailscale on Your Devices

If you're not already using Tailscale, install it on your local machine:

**Desktop:**
1. Go to [tailscale.com/download](https://tailscale.com/download)
2. Download and install for your OS (Windows/Mac/Linux)
3. Sign in with the same account you used on the server

**Mobile:**
1. Install "Tailscale" from App Store / Play Store
2. Sign in with the same account

---

## Step 4: Connect via Tailscale

From your local machine:

```bash
ssh root@<tailscale-ip>
```

This should work without a password (Tailscale SSH handles auth).

Then switch to the pocketdev user:

```bash
su - pocketdev
```

Verify:
```bash
whoami
# Should output: pocketdev
```

---

## Step 5: Deploy PocketDev

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

## Step 6: Configure App for HTTPS

If you enabled Tailscale HTTPS in Step 2, configure PocketDev to use it:

**1. Update your `.env` file:**

```bash
nano .env
```

Update `APP_URL` and enable `FORCE_HTTPS`:
```text
APP_URL=https://<server-name>.<tailnet-name>.ts.net
FORCE_HTTPS=true
```

**2. Clear config cache and restart:**

```bash
docker compose exec pocket-dev-php php artisan config:cache
docker compose restart
```

---

## Step 7: Access PocketDev

Open in your browser (using the hostname from Step 2):

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

### Conversations return empty responses

If you send a message and get no response (SSE stream ends immediately with no content), check the logs:

```bash
docker exec pocket-dev-php tail -20 /var/www/storage/logs/api-*.log | grep -i "dangerously-skip-permissions"
```

If you see `--dangerously-skip-permissions cannot be used with root/sudo privileges`, you're running the containers as root. The Claude CLI refuses this for security reasons.

**Fix:** Create a non-root user and redeploy (see Step 2). If you already deployed as root:

```bash
# As root, create the user
useradd -m -s /bin/bash pocketdev
usermod -aG docker pocketdev

# Move the project
mv /root/pocket-dev /home/pocketdev/
chown -R pocketdev:pocketdev /home/pocketdev/pocket-dev

# Switch to pocketdev and redeploy
su - pocketdev
cd pocket-dev
./setup.sh  # This will set correct USER_ID/GROUP_ID
docker compose down && docker compose up -d --build
```
