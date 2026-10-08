#!/bin/bash
## Do not modify this file. You will lose the ability to autoupdate!

CDN="https://cdn.coollabs.io/coolify-nightly"
LATEST_IMAGE=${1:-latest}
LATEST_HELPER_VERSION=${2:-latest}
ENV_FILE="/data/coolify/source/.env"
if [ -n "${3+x}" ]; then
    REGISTRY_URL="$3"
elif [ -f "$ENV_FILE" ] && grep -q "^REGISTRY_URL=" "$ENV_FILE"; then
    REGISTRY_URL=$(grep "^REGISTRY_URL=" "$ENV_FILE" | cut -d '=' -f2- | head -n1)
else
    REGISTRY_URL="docker.io"
fi
SKIP_BACKUP=${4:-false}
STATUS_FILE="/data/coolify/source/.upgrade-status"

DATE=$(date +%Y-%m-%d-%H-%M-%S)
LOGFILE="/data/coolify/source/upgrade-${DATE}.log"

# Helper function to log with timestamp
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >>"$LOGFILE"
}

# Helper function to log section headers
log_section() {
    echo "" >>"$LOGFILE"
    echo "============================================================" >>"$LOGFILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >>"$LOGFILE"
    echo "============================================================" >>"$LOGFILE"
}

# Helper function to write upgrade status for API polling
write_status() {
    local step="$1"
    local message="$2"
    echo "${step}|${message}|$(date -Iseconds)" > "$STATUS_FILE"
}

# Downloaded files are verified against "<url>.sha256" (CHECKSUM_URL_SUFFIX changes the suffix) before
# they replace the current copy. A mismatch always fails; a missing checksum only warns, unless
# COOLIFY_REQUIRE_CHECKSUMS=true is set in the environment or in .env.
if [ -z "${COOLIFY_REQUIRE_CHECKSUMS:-}" ] && [ -f "$ENV_FILE" ]; then
    COOLIFY_REQUIRE_CHECKSUMS=$(grep "^COOLIFY_REQUIRE_CHECKSUMS=" "$ENV_FILE" | cut -d '=' -f2- | head -n1 || true)
fi
COOLIFY_REQUIRE_CHECKSUMS=${COOLIFY_REQUIRE_CHECKSUMS:-false}
CHECKSUM_URL_SUFFIX=${CHECKSUM_URL_SUFFIX:-.sha256}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        openssl dgst -sha256 -r "$1" | awk '{print $1}'
    fi
}

# Usage: download_verified <url> <destination>
# Returns 1 when the download fails, 2 on a checksum mismatch, 3 when a required checksum is missing.
# The destination is only replaced after a successful check. Set CHECKSUM_WARNINGS_FILE to collect
# the URLs that had no checksum.
download_verified() {
    local url="$1"
    local dest="$2"
    local tmp="${dest}.download"
    local expected actual

    rm -f "$tmp"
    if ! curl -fsSL -L "$url" -o "$tmp"; then
        rm -f "$tmp"
        echo "ERROR: Failed to download $url"
        return 1
    fi

    expected=$(curl -fsSL -L "${url}${CHECKSUM_URL_SUFFIX}" 2>/dev/null | tr -d '\r' | awk 'NR==1 {print tolower($1)}' || true)
    if [[ $expected =~ ^[0-9a-f]{64}$ ]]; then
        actual=$(sha256_of "$tmp")
        if [ "$actual" != "$expected" ]; then
            rm -f "$tmp"
            echo "ERROR: Checksum mismatch for $url (expected $expected, got $actual)"
            return 2
        fi
        echo "Verified SHA-256 checksum of $url"
    elif [ "$COOLIFY_REQUIRE_CHECKSUMS" = "true" ]; then
        rm -f "$tmp"
        echo "ERROR: No checksum published at ${url}${CHECKSUM_URL_SUFFIX} and COOLIFY_REQUIRE_CHECKSUMS=true"
        return 3
    else
        echo "WARNING: No checksum published at ${url}${CHECKSUM_URL_SUFFIX}, the integrity of $url was not verified"
        if [ -n "${CHECKSUM_WARNINGS_FILE:-}" ]; then
            echo "$url" >>"$CHECKSUM_WARNINGS_FILE"
        fi
    fi

    mv -f "$tmp" "$dest"
}
# End of download_verified

echo ""
echo "=========================================="
echo "   Coolify Upgrade - ${DATE}"
echo "=========================================="
echo ""

# Initialize log file with header
echo "============================================================" >>"$LOGFILE"
echo "Coolify Upgrade Log" >>"$LOGFILE"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S')" >>"$LOGFILE"
echo "Target Version: ${LATEST_IMAGE}" >>"$LOGFILE"
echo "Helper Version: ${LATEST_HELPER_VERSION}" >>"$LOGFILE"
echo "Registry URL: ${REGISTRY_URL}" >>"$LOGFILE"
echo "============================================================" >>"$LOGFILE"

log_section "Step 1/6: Downloading configuration files"
write_status "1" "Downloading configuration files"
echo "1/6 Downloading latest configuration files..."
CHECKSUM_WARNINGS_FILE=$(mktemp)

# A file that fails its checksum aborts the upgrade; a failed download keeps the existing copy as before.
fetch_config_file() {
    local name="$1"
    local status=0
    log "Downloading ${name} from ${CDN}/${name}"
    download_verified "$CDN/$name" "/data/coolify/source/$name" >>"$LOGFILE" 2>&1 || status=$?
    if [ "$status" -eq 2 ] || [ "$status" -eq 3 ]; then
        log "ERROR: ${name} failed checksum verification"
        write_status "error" "Checksum verification failed for ${name}"
        echo "     ERROR: ${name} failed checksum verification. Aborting upgrade."
        rm -f "$CHECKSUM_WARNINGS_FILE"
        exit 1
    elif [ "$status" -ne 0 ]; then
        log "WARNING: Failed to download ${name}, keeping the existing file"
    fi
}

fetch_config_file docker-compose.yml
fetch_config_file docker-compose.prod.yml
fetch_config_file .env.production
fetch_config_file upgrade-postgres.sh
chmod +x /data/coolify/source/upgrade-postgres.sh
if [ -s "$CHECKSUM_WARNINGS_FILE" ]; then
    echo "     WARNING: No checksums are published for the configuration files, their integrity was not verified."
    echo "     Set COOLIFY_REQUIRE_CHECKSUMS=true in .env to refuse unverified files."
fi
rm -f "$CHECKSUM_WARNINGS_FILE"
log "Configuration files downloaded successfully"
echo "     Done."

# Extract all images from docker-compose configuration
log "Extracting all images from docker-compose configuration..."
COMPOSE_FILES="-f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml"

# Check if custom compose file exists
if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
    COMPOSE_FILES="$COMPOSE_FILES -f /data/coolify/source/docker-compose.custom.yml"
    log "Including custom docker-compose.yml in image extraction"
fi

# Check if PostgreSQL upgrade override exists
if [ -f /data/coolify/source/docker-compose.postgres-upgrade.yml ]; then
    COMPOSE_FILES="$COMPOSE_FILES -f /data/coolify/source/docker-compose.postgres-upgrade.yml"
    log "Including PostgreSQL upgrade compose override in image extraction"
fi

# Get all unique images from docker compose config
# LATEST_IMAGE env var is needed for image substitution in compose files
IMAGES=$(REGISTRY_URL=${REGISTRY_URL} LATEST_IMAGE=${LATEST_IMAGE} docker compose --env-file "$ENV_FILE" $COMPOSE_FILES config --images 2>/dev/null | sort -u)

if [ -z "$IMAGES" ]; then
    log "ERROR: Failed to extract images from docker-compose files"
    write_status "error" "Failed to parse docker-compose configuration"
    echo "     ERROR: Failed to parse docker-compose configuration. Aborting upgrade."
    exit 1
fi

log "Images to pull:"
echo "$IMAGES" | while read img; do log "  - $img"; done

# Backup existing .env file before making any changes
if [ "$SKIP_BACKUP" != "true" ]; then
    if [ -f "$ENV_FILE" ]; then
        echo "     Creating backup of .env file..."
        log "Creating backup of .env file to .env-$DATE"
        cp "$ENV_FILE" "$ENV_FILE-$DATE"
        log "Backup created: ${ENV_FILE}-${DATE}"
    else
        log "WARNING: No existing .env file found to backup"
    fi
fi

log_section "Step 2/6: Updating environment configuration"
write_status "2" "Updating environment configuration"
echo ""
echo "2/6 Updating environment configuration..."
log "Merging .env.production values into .env"
awk -F '=' '!seen[$1]++' "$ENV_FILE" /data/coolify/source/.env.production > "$ENV_FILE.tmp" && mv "$ENV_FILE.tmp" "$ENV_FILE"
log "Environment file merged successfully"

update_env_var() {
    local key="$1"
    local value="$2"

    # If variable "key=" exists but has no value, update the value of the existing line
    if grep -q "^${key}=$" "$ENV_FILE"; then
        sed -i "s|^${key}=$|${key}=${value}|" "$ENV_FILE"
        log "Updated ${key} (was empty)"
    # If variable "key=" doesn't exist, append it to the file with value
    elif ! grep -q "^${key}=" "$ENV_FILE"; then
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
        log "Added ${key} (was missing)"
    fi
}

set_env_var() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
        log "Updated ${key}"
    else
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
        log "Added ${key}"
    fi
}

log "Checking environment variables..."
set_env_var "REGISTRY_URL" "$REGISTRY_URL"
update_env_var "PUSHER_APP_ID" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"
update_env_var "PUSHER_BACKEND_PORT" "6001"
# The realtime container no longer exists; point older installs at the bundled Reverb server
if grep -q '^PUSHER_BACKEND_HOST=coolify-realtime$' "$ENV_FILE"; then
    set_env_var "PUSHER_BACKEND_HOST" "127.0.0.1"
fi
log "Environment variables check complete"
echo "     Done."

# Make sure coolify network exists
# It is created when Starting PADED with docker compose
log "Checking Docker network 'coolify'..."
if ! docker network inspect coolify >/dev/null 2>&1; then
    log "Network 'coolify' does not exist, creating..."
    if ! docker network create --attachable --ipv6 coolify 2>/dev/null; then
        log "Failed to create network with IPv6, trying without IPv6..."
        docker network create --attachable coolify 2>/dev/null
        log "Network 'coolify' created without IPv6"
    else
        log "Network 'coolify' created with IPv6 support"
    fi
else
    log "Network 'coolify' already exists"
fi

# Check if Docker config file exists
DOCKER_CONFIG_MOUNT=""
if [ -f /root/.docker/config.json ]; then
    DOCKER_CONFIG_MOUNT="-v /root/.docker/config.json:/root/.docker/config.json"
    log "Docker config mount enabled: /root/.docker/config.json"
fi

log_section "Step 3/6: Pulling Docker images"
write_status "3" "Pulling Docker images"
echo ""
echo "3/6 Pulling Docker images..."
echo "     This may take a few minutes depending on your connection."

# Also pull the helper image (not in compose files but needed for upgrade)
HELPER_IMAGE="${REGISTRY_URL:-docker.io}/coollabsio/coolify-helper:${LATEST_HELPER_VERSION}"
echo "     - Pulling $HELPER_IMAGE..."
log "Pulling image: $HELPER_IMAGE"
if docker pull "$HELPER_IMAGE" >>"$LOGFILE" 2>&1; then
    log "Successfully pulled $HELPER_IMAGE"
else
    log "ERROR: Failed to pull $HELPER_IMAGE"
    write_status "error" "Failed to pull $HELPER_IMAGE"
    echo "     ERROR: Failed to pull $HELPER_IMAGE. Aborting upgrade."
    exit 1
fi

# Pull all images from compose config
# Using a for loop to avoid subshell issues with exit
for IMAGE in $IMAGES; do
    if [ -n "$IMAGE" ]; then
        echo "     - Pulling $IMAGE..."
        log "Pulling image: $IMAGE"
        if docker pull "$IMAGE" >>"$LOGFILE" 2>&1; then
            log "Successfully pulled $IMAGE"
        else
            log "ERROR: Failed to pull $IMAGE"
            write_status "error" "Failed to pull $IMAGE"
            echo "     ERROR: Failed to pull $IMAGE. Aborting upgrade."
            exit 1
        fi
    fi
done

log "All images pulled successfully"
echo "     All images pulled successfully."

set_env_var "LATEST_IMAGE" "$LATEST_IMAGE"
set_env_var "COOLIFY_VERSION" "$LATEST_IMAGE"

log_section "Step 4/6: Stopping and restarting containers"
write_status "4" "Stopping containers"
echo ""
echo "4/6 Stopping containers and starting new ones..."
echo "     This step will restart all Coolify containers."
echo "     Check the log file for details: ${LOGFILE}"

# From this point forward, we need to ensure the script continues even if
# the SSH connection is lost (which happens when coolify container stops)
# We use a subshell with nohup to ensure completion
log "Starting container restart sequence (detached)..."

nohup bash -c "
    LOGFILE='$LOGFILE'
    STATUS_FILE='$STATUS_FILE'
    DOCKER_CONFIG_MOUNT='$DOCKER_CONFIG_MOUNT'
    REGISTRY_URL='$REGISTRY_URL'
    LATEST_HELPER_VERSION='$LATEST_HELPER_VERSION'
    LATEST_IMAGE='$LATEST_IMAGE'

    log() {
        echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] \$1\" >>\"\$LOGFILE\"
    }

    write_status() {
        echo \"\$1|\$2|\$(date -Iseconds)\" > \"\$STATUS_FILE\"
    }

    # Stop and remove containers
    # coolify-realtime is kept for upgrades from versions that still ran the separate realtime container
    for container in coolify coolify-db coolify-redis coolify-realtime; do
        if docker ps -a --format '{{.Names}}' | grep -q \"^\${container}\$\"; then
            log \"Stopping container: \${container}\"
            docker stop \"\$container\" >>\"\$LOGFILE\" 2>&1 || true
            log \"Removing container: \${container}\"
            docker rm \"\$container\" >>\"\$LOGFILE\" 2>&1 || true
            log \"Container \${container} stopped and removed\"
        else
            log \"Container \${container} not found (skipping)\"
        fi
    done
    log \"Container cleanup complete\"

    # Start new containers
    echo '' >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"
    log 'Step 5/6: Starting new containers'
    echo '============================================================' >>\"\$LOGFILE\"
    write_status '5' 'Starting new containers'

    COMPOSE_FILES='-f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml'
    if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
        log 'Using custom docker-compose.yml'
        COMPOSE_FILES=\"\$COMPOSE_FILES -f /data/coolify/source/docker-compose.custom.yml\"
    fi
    if [ -f /data/coolify/source/docker-compose.postgres-upgrade.yml ]; then
        log 'Using PostgreSQL upgrade compose override'
        COMPOSE_FILES=\"\$COMPOSE_FILES -f /data/coolify/source/docker-compose.postgres-upgrade.yml\"
    fi

    log 'Running docker compose up...'
    docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock \${DOCKER_CONFIG_MOUNT} --rm \${REGISTRY_URL:-docker.io}/coollabsio/coolify-helper:\${LATEST_HELPER_VERSION} bash -c \"LATEST_IMAGE=\${LATEST_IMAGE} docker compose --env-file /data/coolify/source/.env \${COMPOSE_FILES} up -d --remove-orphans --wait --wait-timeout 60\" >>\"\$LOGFILE\" 2>&1
    log 'Docker compose up completed'

    # Final log entry
    echo '' >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"
    log 'Step 6/6: Upgrade complete'
    echo '============================================================' >>\"\$LOGFILE\"
    write_status '6' 'Upgrade complete'
    log 'Coolify upgrade completed successfully'
    log \"Version: \${LATEST_IMAGE}\"
    echo '' >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"
    echo \"Upgrade completed: \$(date '+%Y-%m-%d %H:%M:%S')\" >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"

    # Clean up status file after a short delay to allow frontend to read completion
    sleep 10
    rm -f \"\$STATUS_FILE\"
    log 'Status file cleaned up'
" >>"$LOGFILE" 2>&1 &

# Give the background process a moment to start
sleep 2
log "Container restart sequence started in background (PID: $!)"
echo ""
echo "5/6 Containers are being restarted in the background..."
echo "6/6 Upgrade process initiated!"
echo ""
echo "=========================================="
echo "   Coolify upgrade to ${LATEST_IMAGE} in progress"
echo "=========================================="
echo ""
echo "   The upgrade will continue in the background."
echo "   Coolify will be available again shortly."
echo "   Log file: ${LOGFILE}"
