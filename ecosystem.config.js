export default {
  apps: [
    {
      name: 'hermes-panel',
      script: 'php',
      args: 'artisan serve --host=0.0.0.0 --port=9119',
      interpreter: 'none',
      cwd: '/home/ZaganJade1/hermes-panel',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '256M',
      env: {
        APP_ENV: 'production',
        APP_DEBUG: 'false'
      },
      exp_backoff_restart_delay: 100,
      max_restarts: 10,
      min_uptime: '5s'
    },
    {
      name: 'desakta',
      script: 'php',
      args: 'artisan serve --host=0.0.0.0 --port=8000',
      interpreter: 'none',
      cwd: '/data/Project/desakta',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '512M',
      env: {
        APP_ENV: 'production',
        APP_DEBUG: 'false'
      },
      exp_backoff_restart_delay: 100,
      max_restarts: 10,
      min_uptime: '5s'
    }
  ]
}