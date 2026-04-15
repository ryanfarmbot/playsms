FROM nginx:stable-alpine

COPY docker/nginx/templates/default.conf.ecs.template /etc/nginx/templates/default.conf.template
