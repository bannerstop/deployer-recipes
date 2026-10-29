FROM python:3.12-alpine

COPY server.py /server.py

HEALTHCHECK --interval=1s --retries=30 CMD wget -qO- http://127.0.0.1:8080/ >/dev/null || exit 1
CMD ["python", "/server.py"]
