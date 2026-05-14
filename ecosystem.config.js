export default {
  apps: [{
    name: 'hermes-panel',
    script: 'php',
    args: 'artisan serve --host=0.0.0.0 --port=8080',
    interpreter: 'none',
    cwd: '/home/ZaganJade1/Project/hermes-panel',
    instances: 1,
    autorestart: true,
    watch: false,
    max_memory_restart: '256M',
    env: {
      APP_ENV: 'production',
      APP_DEBUG: 'false'
    }
  }]
}
