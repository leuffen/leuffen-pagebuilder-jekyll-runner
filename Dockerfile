FROM ghcr.io/nfra-project/kickstart-flavor-php:8.4
ARG BUILD_DATE
LABEL org.opencontainers.image.created="${BUILD_DATE}"
ENV DEV_CONTAINER_NAME="leuffen-pagebuilder-jekyll-runner"

ADD / /opt
RUN ["bash", "-c", "chown -R user /opt"]
RUN ["/kickstart/run/entrypoint.sh", "build"]

ENTRYPOINT ["/kickstart/run/entrypoint.sh", "standalone"]
