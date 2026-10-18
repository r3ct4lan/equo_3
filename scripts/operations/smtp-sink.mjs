// Test-only SMTP peer. Discards all message content; records counters only.
import net from 'node:net';
import fs from 'node:fs';

const root = '/probe-state/';
const increment = (name) => {
  const file = root + name;
  const previous = fs.existsSync(file) ? Number(fs.readFileSync(file, 'utf8')) : 0;
  fs.writeFileSync(file, String(previous + 1));
};
net.createServer((socket) => {
  socket.on('error', () => {});
  const mode = fs.readFileSync(root + 'smtp-mode', 'utf8').trim();
  if (mode === 'fail') {
    increment('rejected');
    socket.end('421 Temporary test failure\r\n');
    return;
  }
  socket.write('220 equo-ops.test ESMTP\r\n');
  let buffer = '';
  let data = false;
  socket.on('data', (chunk) => {
    buffer += chunk.toString();
    let end;
    while ((end = buffer.indexOf('\r\n')) !== -1) {
      const line = buffer.slice(0, end);
      buffer = buffer.slice(end + 2);
      if (data) {
        if (line !== '.') continue;
        data = false;
        increment('received');
        const acknowledge = () => {
          increment('accepted');
          socket.write('250 Accepted\r\n');
        };
        if (mode === 'delay') setTimeout(acknowledge, 12000);
        else acknowledge();
      } else if (/^(EHLO|HELO) /i.test(line)) socket.write('250 equo-ops.test\r\n');
      else if (/^(MAIL FROM:|RCPT TO:|RSET|NOOP)/i.test(line)) socket.write('250 OK\r\n');
      else if (line === 'DATA') {
        data = true;
        socket.write('354 End with dot\r\n');
      } else if (line === 'QUIT') socket.end('221 Bye\r\n');
      else socket.write('500 Unsupported\r\n');
    }
  });
}).listen(1025, '0.0.0.0');
