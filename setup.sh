#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to validate and prepare directory structure
validate_directory() {
    print_info "Validating directory structure..."
    
    # Case 1: Check if we're in an existing Laravel project root
    if [ -f "artisan" ]; then
        print_info "Detected existing Laravel project. Moving files to www/ folder..."
        
        # Create www directory
        mkdir -p www
        
        # Move all files except www to www/ folder (handle setup files gracefully)
        for item in *; do
            if [ "$item" != "www" ] && [ "$item" != "setup.sh" ] && [ "$item" != "compose.yml.template" ] && [ "$item" != "docker" ] && [ "$item" != ".env.example" ]; then
                mv "$item" www/
            fi
        done
        
        # Move hidden files too (like .env, .git, etc.)
        for item in .*; do
            if [ "$item" != "." ] && [ "$item" != ".." ] && [ -e "$item" ]; then
                mv "$item" www/
            fi
        done
        
        print_success "Laravel project moved to www/ folder"
        return
    fi
    
    # Case 2: Check if we're in a prepared directory with www/ folder
    if [ -d "www" ]; then
        # Check if Laravel project has required files
        if [ ! -f "www/artisan" ]; then
            print_error "No 'artisan' file found in 'www/'. Are you sure this is a Laravel project?"
            exit 1
        fi
        
        # Check for unexpected files (allow setup files and www folder)
        visible_files=$(ls -1 2>/dev/null | wc -l)
        if [ "$visible_files" -gt 0 ]; then
            allowed_files="www setup.sh compose.yml docker .env.example CLAUDE.md README.md"
            for file in *; do
                if [ -e "$file" ]; then
                    case " $allowed_files " in
                        *" $file "*) ;;
                        *) 
                            print_error "Unexpected file/folder found: $file"
                            print_info "Expected: empty directory with 'www/' folder containing Laravel"
                            exit 1
                            ;;
                    esac
                fi
            done
        fi
        
        print_success "Directory structure validation passed"
        return
    fi
    
    # Case 3: Neither Laravel root nor prepared directory
    print_error "Invalid directory structure detected"
    print_info ""
    print_info "This setup script expects one of:"
    print_info "  1. Laravel project root (with 'artisan' file) - will move to www/"
    print_info "  2. Empty directory with 'www/' folder containing Laravel"
    print_info ""
    print_info "Current directory contents:"
    ls -la
    exit 1
}

# Check prerequisites
check_prerequisites() {
    print_info "Checking prerequisites..."
    
    if ! command_exists docker; then
        print_error "Docker is not installed. Please install Docker first."
        exit 1
    fi
    
    if ! command_exists docker-compose; then
        print_error "Docker Compose is not installed. Please install Docker Compose first."
        exit 1
    fi
    
    print_success "Prerequisites check passed"
}

# Function to get user input with default value
get_input() {
    local prompt="$1"
    local default="$2"
    local result
    
    read -p "$prompt [$default]: " result
    echo "${result:-$default}"
}

# Main setup function
setup_laravel_docker() {
    print_info "🚀 Laravel Docker Setup"
    echo ""
    
    # Validate directory structure first
    validate_directory
    
    # Set Laravel directory (always "www" now)
    LARAVEL_DIR="www"
    
    # Get project details from user
    PROJECT_NAME=$(get_input "Enter your project name (lowercase, no spaces)" "mylaravel")
    PROJECT_NAME=$(echo "$PROJECT_NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9-]//g')
    
    PRODUCTION_URL=$(get_input "Enter production URL (for .env.production)" "https://example.com")
    
    # Try to auto-detect GitHub repository owner
    DETECTED_OWNER=""
    if git rev-parse --git-dir > /dev/null 2>&1; then
        # Try git remote first
        DETECTED_OWNER=$(git remote get-url origin 2>/dev/null | sed -n 's#.*github\.com[:/]\([^/]*\)/.*#\1#p')
        if [ -z "$DETECTED_OWNER" ]; then
            # Fall back to git config user name
            DETECTED_OWNER=$(git config user.name 2>/dev/null)
        fi
    fi
    
    # Ask user about GitHub repository owner
    if [ -n "$DETECTED_OWNER" ]; then
        print_info "Auto-detected GitHub repository owner: $DETECTED_OWNER"
        USE_DETECTED=$(get_input "Use auto-detected owner for container registry? (yes/no)" "yes")
        if [[ "$USE_DETECTED" =~ ^[Yy]([Ee][Ss])?$ ]]; then
            GITHUB_REPOSITORY_OWNER="$DETECTED_OWNER"
        else
            GITHUB_REPOSITORY_OWNER=$(get_input "Enter GitHub repository owner manually" "${DETECTED_OWNER}")
        fi
    else
        GITHUB_REPOSITORY_OWNER=$(get_input "Enter GitHub repository owner (for container registry)" "$(whoami)")
    fi
    
    # Process compose.yml template
    print_info "Processing docker-compose.yml..."
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g; s/{{LARAVEL_DIR}}/$LARAVEL_DIR/g" compose.yml
    print_success "docker-compose.yml configured"
    
    # Process nginx template
    print_info "Updating nginx configuration..."
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g" docker/shared/nginx/default.conf
    print_success "Nginx configuration updated"
    
    # Copy and process GitHub Action workflow
    print_info "Setting up GitHub Action workflow..."
    mkdir -p .github/workflows
    sed "s/{{PROJECT_NAME}}/$PROJECT_NAME/g" \
        "$(dirname "$0")/docker/local/.github/workflows/docker-build.yml" > .github/workflows/docker-build.yml
    print_success "GitHub Action workflow created"
    
    # Process production compose file
    print_info "Processing production docker-compose file..."
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g; s/{{GITHUB_REPOSITORY_OWNER}}/$GITHUB_REPOSITORY_OWNER/g" docker/production/compose.yml
    print_success "docker/production/compose.yml processed"
    
    # Create environment files
    print_info "Creating .env files..."
    
    # Update .env.example with project-specific values
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g" .env.example
    
    # Create .env from .env.example with generated password
    cp .env.example .env
    sed -i "s/DB_PASSWORD=laravel/DB_PASSWORD=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)/" .env

    # Process docker/production/.env.example
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g" docker/production/.env.example
    sed -i "s#{{PRODUCTION_URL}}#$PRODUCTION_URL#g" docker/production/.env.example
    sed -i "s/DB_PASSWORD=laravel/DB_PASSWORD=CHANGE_THIS_PASSWORD/" docker/production/.env.example
    
    # Process production deployment README
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g; s/{{GITHUB_REPOSITORY_OWNER}}/$GITHUB_REPOSITORY_OWNER/g" docker/production/README.md
    
    print_success "Production deployment package created in docker/production/"
    
    # Configure Vite for Docker if needed
    if [ -f "$LARAVEL_DIR/vite.config.js" ]; then
        print_info "Configuring Vite for Docker..."
        if ! grep -q "server:" "$LARAVEL_DIR/vite.config.js"; then
            # Add server configuration for Docker before the closing brace
            sed -i '/^});$/i\    server: {\
        host: '\''0.0.0.0'\'',\
        port: 5173,\
        hmr: {\
            host: '\''localhost'\'',\
        },\
    },' "$LARAVEL_DIR/vite.config.js"
            print_success "Vite configured for Docker (server block added)"
        else
            print_info "Vite server configuration already exists, skipping"
        fi
    fi
    
    # Clean up development files not needed for end users
    if [ -f "CLAUDE.md" ]; then
        print_info "Removing development documentation..."
        rm -f CLAUDE.md
    fi
    
    # Note: Laravel database configuration is handled via environment variables
    
    print_success "🎉 Setup completed successfully!"
    print_info ""
    print_info "Development setup:"
    print_info "1. Run: docker-compose up -d"
    print_info "2. Your Laravel app will be available at: http://localhost"
    print_info ""
    print_info "Production deployment:"
    print_info "1. Create GitHub release to trigger image build"
    print_info "2. Deploy with: docker-compose -f compose.production.yml up -d"
    print_info "3. Use built images: ghcr.io/$GITHUB_REPOSITORY_OWNER/$PROJECT_NAME-php:latest"
    print_info ""
    print_info "Database connection details:"
    print_info "  Host: $PROJECT_NAME-postgres"
    print_info "  Database: $PROJECT_NAME"
    print_info "  Username: $PROJECT_NAME"
    print_info "  Password: [auto-generated for development]"
}


# Display usage information
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  -h, --help     Show this help message"
    echo "  --check        Check prerequisites only"
    echo ""
    echo "This script sets up Laravel with Docker (PHP 8.4-FPM + Nginx + PostgreSQL)"
}

# Parse command line arguments
case "${1:-}" in
    -h|--help)
        usage
        exit 0
        ;;
    --check)
        check_prerequisites
        exit 0
        ;;
    "")
        # No arguments, proceed with setup
        ;;
    *)
        print_error "Unknown option: $1"
        usage
        exit 1
        ;;
esac

# Run the setup
check_prerequisites
setup_laravel_docker