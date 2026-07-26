FROM node:22-bookworm-slim
WORKDIR /app
RUN npm install --global npm@11.6.2
COPY package.json package-lock.json ./
COPY scripts ./scripts
RUN npm install
COPY . .
EXPOSE 3000
CMD ["npm", "run", "dev"]
