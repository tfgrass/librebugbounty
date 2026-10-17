FROM mcr.microsoft.com/playwright:v1.61.1-jammy

# Exact Ubuntu Jammy package versions; no pip/global npm VNC dependency drift.
# Node and Playwright remain supplied by the same image as the shot lanes.
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        x11vnc=0.9.16-8 \
        websockify=0.10.0+dfsg1-2build1 \
    && rm -rf /var/lib/apt/lists/*

# No EXPOSE for 5900/6080: both listeners must remain on container loopback.
