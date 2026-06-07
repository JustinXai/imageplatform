module.exports = {
  apps: [
    {
      name: "imageplatform-worker",
      script: "php",
      args: "worker/generate_worker.php",
      cwd: "/home/ubuntu/imageplatform",
      interpreter: "none",
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: "256M",
      env: {
        PHP_CLI_ARGS: "",
      },
      error_file: "/var/log/pm2/imageplatform-worker-error.log",
      out_file: "/var/log/pm2/imageplatform-worker-out.log",
      log_file: "/var/log/pm2/imageplatform-worker-combined.log",
      time: true,
    },
  ],
};
