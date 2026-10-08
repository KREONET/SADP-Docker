#!/bin/bash
## Builds the PADED image from this checkout and runs it in place of the official Coolify image.
##
## scripts/install.sh installs the official Coolify image, which has none of the PADED changes.
## Run this after install.sh, and again after every `git pull`, to deploy what is in this checkout.
##
## Environment variables:
## PADED_IMAGE_TAG - Image tag to build and run (default: paded)
## SKIP_BUILD      - Set to "true" to run an image that is already built

set -euo pipefail

REPO_DIR=$(cd "$(dirname "$0")/.." && pwd)
SOURCE_DIR=/data/coolify/source
ENV_FILE="$SOURCE_DIR/.env"
IMAGE_TAG=${PADED_IMAGE_TAG:-paded}
IMAGE="coollabsio/coolify:${IMAGE_TAG}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash scripts/paded-deploy.sh"
    exit 1
fi

if [ ! -f "$ENV_FILE" ]; then
    echo "$ENV_FILE not found. Run scripts/install.sh first."
    exit 1
fi

# Sets KEY=VALUE in the .env file, replacing an existing value (install.sh only fills empty ones)
set_env_var() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
    fi
}

if [ "${SKIP_BUILD:-false}" != "true" ]; then
    echo "1/3 Building ${IMAGE} from ${REPO_DIR}..."
    docker build -f "$REPO_DIR/docker/production/Dockerfile" -t "$IMAGE" "$REPO_DIR"
else
    echo "1/3 Skipping build, using ${IMAGE}"
fi

echo "2/3 Installing the PADED compose files..."
DATE=$(date +%Y%m%d-%H%M%S)
cp "$ENV_FILE" "$ENV_FILE-$DATE"
cp "$REPO_DIR/docker-compose.yml" "$REPO_DIR/docker-compose.prod.yml" "$SOURCE_DIR/"
# Restarts keep the PADED image, and auto-update must not replace it with the official one
set_env_var "LATEST_IMAGE" "$IMAGE_TAG"
set_env_var "AUTOUPDATE" "false"

echo "3/3 Starting PADED..."
cd "$SOURCE_DIR"
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.prod.yml \
    up -d --remove-orphans --wait --wait-timeout 300

echo ""
echo "PADED is running ${IMAGE}. Database migrations ran when the container started."
echo "Previous .env saved as ${ENV_FILE}-${DATE}"
