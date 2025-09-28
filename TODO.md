# PocketDev TODO List

## 1. Consolidate Docker TTYD Structure

Move Docker TTYD files into a shared folder structure for consistency with docker-proxy:

- **Current structure:** Files directly under `docker-ttyd/`
- **Target structure:** Move to `docker-ttyd/shared/`
- **Files to move:**
  - `docker-ttyd/Dockerfile` → `docker-ttyd/shared/Dockerfile`
  - `docker-ttyd/entrypoint.sh` → `docker-ttyd/shared/entrypoint.sh`
- **Update references:**
  - `compose.yml` - Update dockerfile path
  - `.github/workflows/docker-laravel.yml` - Update dockerfile path

## 2. Enhance GitHub Actions Build Process

Add dependency installation steps to the CI/CD pipeline:

- **Add to `.github/workflows/docker-laravel.yml`:**
  - Add `composer install` command for PHP dependencies
  - Add `npm ci` command for Node.js dependencies
- **Purpose:** Ensure all dependencies are properly installed during the build process
- **Location:** Add these steps before the Docker build steps

## 3. Update Deployment to Use GitHub Container Registry Images

Modify deployment configuration to use pre-built images from GitHub Container Registry:

- **Current:** Building images locally during deployment
- **Target:** Pull and use images from `ghcr.io/${{ github.repository_owner }}/pocket-dev-*`
- **Images to update:**
  - `pocket-dev-php`
  - `pocket-dev-nginx`
  - `pocket-dev-ttyd`
  - `pocket-dev-proxy`
- **Files to modify:**
  - Production docker-compose file (or deployment scripts)
  - Ensure image tags match the GitHub Actions output
- **Benefit:** Faster deployments using pre-built, tested images