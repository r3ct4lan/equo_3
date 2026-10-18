FROM nginx:1.28-alpine
COPY docker/staging/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY backend/public /var/www/backend/public
