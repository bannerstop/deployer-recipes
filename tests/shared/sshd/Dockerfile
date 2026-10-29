# Throwaway deploy target that also hosts the git repository the deployments pull from
FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-server git \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/sshd /srv/git \
    && useradd --create-home --shell /bin/bash deploy \
    && usermod --password '*' deploy \
    && chown deploy:deploy /srv/git

# The deployments clone a GitHub URL (so the recipe can build links); it resolves to the local repository
RUN git config --system url./srv/git/app.git.insteadOf https://github.com/acme/app.git

COPY entrypoint.sh /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
