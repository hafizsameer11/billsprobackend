# Deployment Guide for Dokploy

This guide explains how to deploy the Bill's Pro Backend application on Dokploy using Docker.

## Prerequisites

- Dokploy instance set up and running
- Database (MySQL/MariaDB) accessible from Dokploy
- Git repository with the application code

## Environment Variables

Set the following environment variables in Dokploy:

### Application
```
APP_NAME="Bill's Pro API"
APP_ENV=production
APP_KEY=base64:YOUR_APP_KEY_HERE
APP_DEBUG=false
APP_URL=https://your-domain.com
```

### Database
```
DB_CONNECTION=mysql
DB_HOST=your-database-host
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
```

### Cache & Session
```
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
```

### Mail (Optional)
```
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

### Swagger Documentation
```
L5_SWAGGER_GENERATE_ALWAYS=true
L5_SWAGGER_CONST_HOST=https://your-domain.com
```

## Deployment Steps

### 1. Connect Repository to Dokploy

1. Go to your Dokploy dashboard
2. Click "New Application"
3. Connect your Git repository
4. Select the branch (usually `main` or `master`)

### 2. Configure Build Settings

- **Dockerfile Path**: `Dockerfile` (root directory)
- **Build Context**: `.` (root directory)
- **Port**: `80`

### 3. Set Environment Variables

Add all environment variables listed above in the Dokploy environment variables section.

### 4. Database Setup

Before deploying, ensure your database is accessible and create the database:

```sql
CREATE DATABASE your_database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Deploy Application

1. Click "Deploy" in Dokploy
2. Wait for the build to complete
3. Check logs for any errors

### 6. Run Migrations

After first deployment, run migrations:

```bash
# SSH into the container or use Dokploy's terminal
php artisan migrate --force
```

### 7. Run Seeders (Optional)

If you need initial data:

```bash
php artisan db:seed --force
```

### 8. Generate Swagger Documentation

```bash
php artisan l5-swagger:generate
```

## Post-Deployment

### 1. Verify Storage Permissions

Ensure storage directories have correct permissions:

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 2. Clear and Cache Configuration

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### 3. Test API Endpoints

- Health check: `GET /api/user` (requires authentication)
- Swagger docs: `GET /api/documentation`

## Troubleshooting

### Permission Issues

If you encounter permission errors:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Database Connection Issues

- Verify database credentials in environment variables
- Check if database host is accessible from Dokploy
- Ensure database user has proper permissions

### 500 Errors

- Check application logs: `storage/logs/laravel.log`
- Verify `APP_KEY` is set
- Check file permissions
- Review PHP error logs

### Swagger Not Loading

- Run: `php artisan l5-swagger:generate`
- Check `storage/api-docs/api-docs.json` exists
- Verify `L5_SWAGGER_CONST_HOST` matches your domain

## Maintenance

### Update Application

1. Push changes to your Git repository
2. Dokploy will automatically detect changes
3. Click "Redeploy" or set up auto-deploy

### Clear Caches

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### View Logs

Access logs through Dokploy dashboard or:

```bash
tail -f storage/logs/laravel.log
```

## Security Considerations

1. **Never commit `.env` file** - Use environment variables in Dokploy
2. **Set `APP_DEBUG=false`** in production
3. **Use strong `APP_KEY`** - Generate with `php artisan key:generate`
4. **Enable HTTPS** - Configure SSL in Dokploy
5. **Database Security** - Use strong passwords and limit access
6. **Regular Updates** - Keep dependencies updated

## Performance Optimization

The Dockerfile includes:
- OPcache enabled for better PHP performance
- Nginx with gzip compression
- Optimized autoloader
- Cached configuration, routes, and views

## Support

For issues or questions:
- Check application logs
- Review Dokploy logs
- Verify environment variables
- Test database connectivity
