FROM node:22-bookworm

WORKDIR /workspace/frontend

RUN npm install --global npm@11.6.2

COPY frontend/package.json frontend/package-lock.json ./
COPY frontend/scripts ./scripts
RUN npm ci
RUN npx playwright install --with-deps chromium

COPY frontend ./

CMD ["npm", "run", "test:e2e"]
