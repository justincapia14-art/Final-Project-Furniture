import { spawn } from 'node:child_process';

const port = Number(process.env.PORT || 3000);
const phpServer = spawn('php', ['-S', `0.0.0.0:${port}`, 'router.php'], {
  stdio: 'inherit',
  env: { ...process.env },
});

phpServer.on('error', (err) => {
  console.error('Failed to launch PHP built-in server:', err.message);
  process.exit(1);
});

process.on('SIGTERM', () => phpServer.kill('SIGTERM'));
process.on('SIGINT', () => phpServer.kill('SIGINT'));
