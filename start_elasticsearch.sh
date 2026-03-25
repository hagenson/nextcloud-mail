#!/usr/bin/env bash
# start_elasticsearch.sh
# Starts a single-node ElasticSearch 8 container for local development.
# Security (TLS + auth) is disabled so no credentials are needed.
#
# Usage:
#   ./start_elasticsearch.sh          # start (or restart) the container
#   ./start_elasticsearch.sh stop     # stop and remove the container
#   ./start_elasticsearch.sh logs     # tail container logs
#   ./start_elasticsearch.sh status   # print health status

set -euo pipefail

CONTAINER_NAME="nextcloud-mail-elasticsearch"
ES_IMAGE="docker.elastic.co/elasticsearch/elasticsearch:8.17.4"
ES_PORT=9200
ES_JAVA_OPTS="-Xms512m -Xmx512m"

# ── Helpers ──────────────────────────────────────────────────────────────────

info()    { echo -e "\033[0;34m[info]\033[0m  $*"; }
success() { echo -e "\033[0;32m[ok]\033[0m    $*"; }
warn()    { echo -e "\033[0;33m[warn]\033[0m  $*"; }
die()     { echo -e "\033[0;31m[error]\033[0m $*" >&2; exit 1; }

require_docker() {
    command -v docker >/dev/null 2>&1 || die "Docker is not installed or not on PATH."
    docker info >/dev/null 2>&1      || die "Docker daemon is not running."
}

wait_healthy() {
    local max_attempts=12
    local attempt=0
    info "Waiting for ElasticSearch to be ready on port ${ES_PORT}..."
    until curl -sf "http://localhost:${ES_PORT}/_cluster/health" >/dev/null 2>&1; do
        attempt=$(( attempt + 1 ))
        if [[ $attempt -ge $max_attempts ]]; then
            die "ElasticSearch did not become healthy after ${max_attempts} attempts."
        fi
        sleep 10
    done
    success "ElasticSearch is up."
}

print_config() {
    echo ""
    echo "  Configure the Nextcloud mail app with:"
    echo ""
    echo "    occ config:app:set mail elasticsearch_enabled  --value=1"
    echo "    occ config:app:set mail elasticsearch_host     --value=http://localhost:${ES_PORT}"
    echo "    occ config:app:set mail elasticsearch_index    --value=nextcloud_mail"
    echo ""
    echo "  Then build the initial index:"
    echo ""
    echo "    occ mail:search:index"
    echo ""
}

# ── Sub-commands ─────────────────────────────────────────────────────────────

cmd_stop() {
    require_docker
    if docker ps -q --filter "name=^${CONTAINER_NAME}$" | grep -q .; then
        info "Stopping container '${CONTAINER_NAME}'..."
        docker stop "${CONTAINER_NAME}" >/dev/null
        success "Stopped."
    else
        warn "Container '${CONTAINER_NAME}' is not running."
    fi
    if docker ps -aq --filter "name=^${CONTAINER_NAME}$" | grep -q .; then
        info "Removing container '${CONTAINER_NAME}'..."
        docker rm "${CONTAINER_NAME}" >/dev/null
        success "Removed."
    fi
}

cmd_logs() {
    require_docker
    docker ps -q --filter "name=^${CONTAINER_NAME}$" | grep -q . \
        || die "Container '${CONTAINER_NAME}' is not running."
    docker logs -f "${CONTAINER_NAME}"
}

cmd_status() {
    require_docker
    if ! docker ps -q --filter "name=^${CONTAINER_NAME}$" | grep -q .; then
        warn "Container '${CONTAINER_NAME}' is not running."
        exit 0
    fi
    info "Container status:"
    docker ps --filter "name=^${CONTAINER_NAME}$" --format \
        "  ID={{.ID}}  Status={{.Status}}  Ports={{.Ports}}"
    echo ""
    info "Cluster health:"
    curl -sf "http://localhost:${ES_PORT}/_cluster/health?pretty" 2>/dev/null \
        || warn "Could not reach http://localhost:${ES_PORT} — is the container still starting?"
}

cmd_start() {
    require_docker

    # If already running, just report and exit.
    if docker ps -q --filter "name=^${CONTAINER_NAME}$" | grep -q .; then
        success "Container '${CONTAINER_NAME}' is already running."
        cmd_status
        print_config
        exit 0
    fi

    # Remove a stopped container with the same name so `docker run` succeeds.
    if docker ps -aq --filter "name=^${CONTAINER_NAME}$" | grep -q .; then
        info "Removing stopped container '${CONTAINER_NAME}'..."
        docker rm "${CONTAINER_NAME}" >/dev/null
    fi

    info "Pulling image ${ES_IMAGE} (skipped if already cached)..."
    docker pull "${ES_IMAGE}"

    info "Starting ElasticSearch container '${CONTAINER_NAME}'..."
    docker run -d \
        --name "${CONTAINER_NAME}" \
        -p "${ES_PORT}:9200" \
        -e "discovery.type=single-node" \
        -e "xpack.security.enabled=false" \
        -e "xpack.security.http.ssl.enabled=false" \
        -e "ES_JAVA_OPTS=${ES_JAVA_OPTS}" \
        "${ES_IMAGE}" >/dev/null

    wait_healthy
    print_config
}

# ── Entry point ───────────────────────────────────────────────────────────────

case "${1:-start}" in
    start)  cmd_start  ;;
    stop)   cmd_stop   ;;
    logs)   cmd_logs   ;;
    status) cmd_status ;;
    *)
        echo "Usage: $0 {start|stop|logs|status}"
        exit 1
        ;;
esac
