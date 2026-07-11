#!/bin/bash
# =============================================================================
# PocketDev Server Setup Script
# =============================================================================
#
# This script secures a fresh Linux server (Ubuntu/Debian) for running
# PocketDev with Tailscale-only access. After running this, your server
# will be invisible to the public internet.
#
# PREREQUISITES:
# 1. Tailscale installed and connected
# 2. SSH'd into server via Tailscale IP (not public IP)
#
# See docs/deployment/secure-server-setup.md for the full guide.
#
# =============================================================================

set -e  # Exit on any error

# -----------------------------------------------------------------------------
# Colors for output
# -----------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# -----------------------------------------------------------------------------
# Pre-flight checks
# -----------------------------------------------------------------------------
log_info "Running pre-flight checks..."

# Check if running as root or with sudo
if [ "$EUID" -ne 0 ]; then
    log_error "Please run with sudo: sudo ./server-setup.sh"
    exit 1
fi

# Check if Tailscale is installed and connected
if ! command -v tailscale &> /dev/null; then
    log_error "Tailscale is not installed. Please install it first:"
    echo "  curl -fsSL https://tailscale.com/install.sh | sh"
    echo "  sudo tailscale up --ssh"
    exit 1
fi

if ! tailscale status &> /dev/null; then
    log_error "Tailscale is not connected. Please run: sudo tailscale up --ssh"
    exit 1
fi

TAILSCALE_IP=$(tailscale ip -4)
log_info "Tailscale connected with IP: $TAILSCALE_IP"

# Detect distro (ubuntu or debian)
if [ -f /etc/os-release ]; then
    . /etc/os-release
    DISTRO_ID="$ID"
    DISTRO_CODENAME="$VERSION_CODENAME"
else
    log_error "Cannot detect Linux distribution. /etc/os-release not found."
    exit 1
fi

if [ "$DISTRO_ID" != "ubuntu" ] && [ "$DISTRO_ID" != "debian" ]; then
    log_error "This script only supports Ubuntu and Debian. Detected: $DISTRO_ID"
    exit 1
fi

log_info "Detected distribution: $DISTRO_ID $DISTRO_CODENAME"

# Confirm with user
echo ""
log_warn "This script will:"
echo "  1. Update the system and install git, nano"
echo "  2. Enable automatic security updates"
echo "  3. Install Docker with log rotation"
echo "  4. Configure iptables to ONLY allow Tailscale access"
echo "  5. Create a 2GB swap file (if none exists)"
echo ""
log_warn "Make sure you're connected via Tailscale SSH (IP: $TAILSCALE_IP)"
log_warn "If you're connected via public IP, you will be locked out!"
echo ""
read -p "Are you connected via Tailscale and ready to proceed? (yes/no): " CONFIRM

if [ "$CONFIRM" != "yes" ]; then
    log_info "Aborted. Connect via Tailscale first, then run again."
    exit 0
fi

# =============================================================================
# STEP 1: System Updates
# =============================================================================
# Keep the system secure with latest patches. We also configure unattended
# upgrades so security patches are automatically applied.
# -----------------------------------------------------------------------------

log_info "Step 1: Updating system packages..."

# Prevent interactive prompts during upgrades
export DEBIAN_FRONTEND=noninteractive

# Configure needrestart to automatically restart services (no prompts)
# This prevents the script from hanging on "Which services should be restarted?"
if [ -d /etc/needrestart ]; then
    mkdir -p /etc/needrestart/conf.d
    echo '$nrconf{restart} = "a";' > /etc/needrestart/conf.d/no-prompt.conf
fi

apt-get update
apt-get upgrade -y

# Install essential tools
log_info "Installing essential tools (git, nano)..."
apt-get install -y git nano

# -----------------------------------------------------------------------------
# Install unattended-upgrades for automatic security patches
# This ensures critical security updates are applied automatically
# -----------------------------------------------------------------------------
log_info "Configuring automatic security updates..."

apt-get install -y unattended-upgrades

# Enable automatic updates for security patches only
cat > /etc/apt/apt.conf.d/50unattended-upgrades << 'EOF'
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
};
Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
EOF

# Enable the unattended-upgrades service
cat > /etc/apt/apt.conf.d/20auto-upgrades << 'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF

systemctl enable unattended-upgrades
systemctl start unattended-upgrades

log_info "Automatic security updates configured"

# =============================================================================
# STEP 2: Install Docker
# =============================================================================
# Docker is required to run PocketDev. We install from the official Docker
# repository to get the latest stable version.
# -----------------------------------------------------------------------------

log_info "Step 2: Installing Docker..."

# Install prerequisites
apt-get install -y ca-certificates curl gnupg

# Add Docker's official GPG key
install -m 0755 -d /etc/apt/keyrings
curl -fsSL "https://download.docker.com/linux/$DISTRO_ID/gpg" -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

# Add Docker repository (uses detected distro)
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$DISTRO_ID \
  $DISTRO_CODENAME stable" | \
  tee /etc/apt/sources.list.d/docker.list > /dev/null

apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# -----------------------------------------------------------------------------
# Configure Docker daemon
# - Log rotation: Prevents Docker logs from filling up disk
# - Default log size: 10MB per container, 3 files max
# -----------------------------------------------------------------------------
log_info "Configuring Docker daemon..."

mkdir -p /etc/docker
cat > /etc/docker/daemon.json << 'EOF'
{
    "log-driver": "json-file",
    "log-opts": {
        "max-size": "10m",
        "max-file": "3"
    }
}
EOF

systemctl restart docker

# Add current user to docker group (so you don't need sudo for docker commands)
# Note: $SUDO_USER is the user who ran sudo, not root
if [ -n "$SUDO_USER" ]; then
    usermod -aG docker "$SUDO_USER"
    log_info "Added $SUDO_USER to docker group (re-login required)"
fi

log_info "Docker installed and configured"

# =============================================================================
# STEP 3: Configure iptables (Firewall)
# =============================================================================
# This is the core security configuration. We block ALL incoming traffic
# except through Tailscale. This makes the server invisible to port scanners
# and attackers on the public internet.
# -----------------------------------------------------------------------------

log_info "Step 3: Configuring iptables firewall..."

# Install iptables-persistent to save rules across reboots
# Pre-configure to auto-save (no interactive prompts)
echo iptables-persistent iptables-persistent/autosave_v4 boolean true | debconf-set-selections
echo iptables-persistent iptables-persistent/autosave_v6 boolean true | debconf-set-selections
apt-get install -y iptables-persistent

# -----------------------------------------------------------------------------
# IPv4 Rules - Host Protection
# -----------------------------------------------------------------------------
log_info "Configuring IPv4 firewall rules..."

# Flush existing rules (start clean)
iptables -F INPUT
iptables -F FORWARD

# Rule 1: Allow loopback (localhost) traffic
# Required for local services to communicate with each other
iptables -A INPUT -i lo -j ACCEPT

# Rule 2: Allow established/related connections
# This allows responses to outbound connections (apt updates, etc.)
iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

# Rule 3: Allow ICMP (ping)
# Useful for network diagnostics. Can be removed for extra stealth.
iptables -A INPUT -p icmp -j ACCEPT

# Rule 4: Allow ALL traffic from Tailscale interface
# This is the key rule - anything coming through Tailscale is trusted
iptables -A INPUT -i tailscale0 -j ACCEPT

# Rule 5: Allow Tailscale's WireGuard UDP port
# Tailscale needs this port open on the public interface to establish
# connections with the Tailscale network
iptables -A INPUT -p udp --dport 41641 -j ACCEPT

# Rule 6: Allow forwarding for Docker networks (needed for builds)
# Docker build containers need to reach the internet for package downloads
iptables -I FORWARD -s 172.16.0.0/12 -j ACCEPT
iptables -I FORWARD -d 172.16.0.0/12 -j ACCEPT

# Rule 7: Default DROP - block everything else
# Any traffic not matching the above rules is silently dropped
iptables -P INPUT DROP
iptables -P FORWARD DROP
iptables -P OUTPUT ACCEPT

# -----------------------------------------------------------------------------
# IPv6 Rules - Block Everything
# -----------------------------------------------------------------------------
# We disable IPv6 incoming since Tailscale primarily uses IPv4.
# This reduces attack surface.
log_info "Configuring IPv6 firewall rules..."

ip6tables -F INPUT
ip6tables -F FORWARD
ip6tables -A INPUT -i lo -j ACCEPT
ip6tables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
ip6tables -A INPUT -p ipv6-icmp -j ACCEPT
ip6tables -A INPUT -i tailscale0 -j ACCEPT
ip6tables -P INPUT DROP
ip6tables -P FORWARD DROP
ip6tables -P OUTPUT ACCEPT

# -----------------------------------------------------------------------------
# Docker-specific Rules (DOCKER-USER chain)
# -----------------------------------------------------------------------------
# Docker bypasses normal iptables rules by inserting its own chains.
# The DOCKER-USER chain is designed for user rules that Docker won't override.
# We block all external access to Docker containers except via Tailscale.
log_info "Configuring Docker firewall rules..."

# Flush existing DOCKER-USER rules
iptables -F DOCKER-USER 2>/dev/null || true

# Allow established connections (responses to outbound requests)
iptables -I DOCKER-USER -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN

# Allow traffic from Tailscale interface to reach containers
iptables -I DOCKER-USER -i tailscale0 -j RETURN

# Allow Docker internal traffic (container-to-container communication)
iptables -I DOCKER-USER -i docker0 -j RETURN

# Allow traffic from Docker networks (172.16.0.0/12 covers Docker's default range)
iptables -I DOCKER-USER -s 172.16.0.0/12 -j RETURN

# Allow traffic from Docker bridge networks (172.17+ range)
iptables -I DOCKER-USER -i br-+ -j RETURN

# Block everything else trying to reach Docker containers
# This is inserted at the END of DOCKER-USER chain
iptables -A DOCKER-USER -j DROP

# -----------------------------------------------------------------------------
# Save iptables rules
# -----------------------------------------------------------------------------
log_info "Saving firewall rules..."

iptables-save > /etc/iptables/rules.v4
ip6tables-save > /etc/iptables/rules.v6

log_info "Firewall configured - only Tailscale access allowed"

# =============================================================================
# STEP 4: Create Swap File (Optional but Recommended)
# =============================================================================
# Swap provides overflow memory when RAM is full. Useful for small VPS
# instances (1-2GB RAM) to prevent out-of-memory crashes.
# -----------------------------------------------------------------------------

log_info "Step 4: Configuring swap..."

# Only create swap if none exists
if [ ! -f /swapfile ] && [ "$(swapon --show | wc -l)" -eq 0 ]; then
    # Create 2GB swap file
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile

    # Make swap permanent (idempotent)
    grep -qxF '/swapfile none swap sw 0 0' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab

    # Optimize swap settings (idempotent)
    # swappiness=10 means only use swap when RAM is nearly full
    grep -qxF 'vm.swappiness=10' /etc/sysctl.conf || echo 'vm.swappiness=10' >> /etc/sysctl.conf
    sysctl vm.swappiness=10

    log_info "2GB swap file created"
else
    log_info "Swap already exists, skipping"
fi

# =============================================================================
# COMPLETE
# =============================================================================

echo ""
echo "============================================================================="
echo -e "${GREEN}Setup Complete!${NC}"
echo "============================================================================="
echo ""
echo "Your server is now configured with:"
echo "  - Automatic security updates"
echo "  - Docker with log rotation"
echo "  - Firewall blocking ALL public access"
echo "  - Only Tailscale connections allowed"
echo ""
echo "============================================================================="
echo ""

# Remind about re-login
if [ -n "$SUDO_USER" ]; then
    log_warn "Remember to log out and back in for docker group changes!"
fi
