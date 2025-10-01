# PocketDev - Deployment Package

Pre-built Docker Compose configuration for production deployment.

**For setup instructions, see the [main README](https://github.com/tetrixdev/pocket-dev#-production-deployment).**

## Updates

```bash
# Update to latest
docker compose pull && docker compose up -d

# Deploy specific version
IMAGE_TAG=v1.4.0 docker compose pull && docker compose up -d
```

## Backup

```bash
# Database backup
docker compose exec pocket-dev-postgres pg_dump -U pocket-dev pocket-dev > backup-$(date +%Y%m%d).sql

# Database restore
docker compose exec -i pocket-dev-postgres psql -U pocket-dev -d pocket-dev < backup.sql
```

## Troubleshooting

```bash
# Check service health
docker compose ps

# View logs
docker compose logs -f [service-name]

# Restart services
docker compose restart [service-name]
```

---

**TODO:** Add automated SSL certificate management with certbot
