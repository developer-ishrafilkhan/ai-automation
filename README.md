# AI Automation Platform

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript&logoColor=white)](https://www.typescriptlang.org)
[![TailwindCSS](https://img.shields.io/badge/Tailwind-4-38B2AC?logo=tailwind-css&logoColor=white)](https://tailwindcss.com)

**AI Automation Platform** is a 100% free and open-source AI workflow orchestration and media generation studio. Build multi-step AI pipelines on an interactive visual canvas that research, write copy, generate images and videos, and publish through MCP connectors.

Anyone can install and run this on `localhost`, self-host on a cloud server/VPS, or deploy it as a multi-tenant **SaaS** model for their own team or clients.

---

## ✨ Features

- **Free for Everyone**: No subscription paywalls, no monthly fees, and no artificial execution limits. Every feature is unlocked.
- **Interactive Visual Canvas**: Drag and drop nodes (Text, Image, Video, Deep Research, MCP Connectors) powered by `@xyflow/react`.
- **Intelligent Multi-Model Routing**: Assign best-fit models per task (Claude for research, OpenAI for images, Gemini for video, DeepSeek for bulk copy) with automatic failover fallback on API rate limits.
- **Deep Research with Live Sources**: Autonomous research nodes crawl web pages, cross-check facts, and return cited reports to downstream nodes.
- **First-Class Media Studio**: Built-in asset library with image color palette analysis, consistent character sheets, and automatic video joining using FFmpeg.
- **Model Context Protocol (MCP)**: Native connectors for GitHub, Slack, Google Drive, Notion, HubSpot, and custom MCP servers.
- **Public Shareable Web Forms**: Convert any AI pipeline into a public interactive form (no login required for clients) with custom rate limits.
- **Bring Your Own Keys (BYOK)**: Pay AI providers directly at wholesale token rates. No middleman markup.
- **Bank-Grade Key Security**: Each workspace possesses its own encrypted data key using **AES-256-GCM**. Keys are decrypted only in memory during execution, never returned to browsers, and sanitized from logs and error traces.

---

## 🔒 Security & Privacy Notice

> [!IMPORTANT]
> **No database credentials, secrets, or API keys are stored in this repository.**
> - The application relies solely on your local `.env` configuration.
> - All AI provider keys you enter through the web interface are securely encrypted into your private database using AES-256-GCM.
> - Your personal database dumps and `.env` files are strictly excluded via `.gitignore`.

---

## 📋 Requirements

- **PHP**: `^8.2` (with extensions: `pdo`, `mbstring`, `openssl`, `curl`, `bcmath`, `fileinfo`)
- **Composer**: `^2.x`
- **Node.js**: `^18.x` or `^20.x` & `npm`
- **Database**: MySQL 8.0+ or SQLite 3
- **FFmpeg** *(Optional, recommended)*: Used for joining multi-scene video clips.

---

## 🚀 Quickstart: Local Installation

### 1. Clone the Repository
```bash
git clone https://github.com/developer-ishrafilkhan/ai-automation.git
cd ai-automation
```

### 2. Install Dependencies
```bash
composer install
npm install
```

### 3. Environment Configuration
Copy the template environment file:
```bash
cp .env.example .env
```

Generate the Laravel application key and Vault master encryption key:
```bash
php artisan key:generate
php artisan vault:key
```

### 4. Database Setup
Set up your database in `.env`:

**For MySQL:**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aiautomation
DB_USERNAME=root
DB_PASSWORD=your_password
```

**For SQLite:**
```env
DB_CONNECTION=sqlite
```
*(For SQLite, create the database file if needed: `touch database/database.sqlite`)*

Run migrations:
```bash
php artisan migrate
```

Link public storage for media assets:
```bash
php artisan storage:link
```

### 5. Start the Application
Run the web development server:
```bash
composer run dev
```

In a separate terminal, start the background queue worker (required to process asynchronous AI runs, video renders, and research steps):
```bash
php artisan queue:work --timeout=1000
```

Open your browser at **[http://localhost:8000](http://localhost:8000)** (or your local web server URL). Register an account, add your API keys under **Keys**, and start creating workflows!

---

## 🌐 Deploying to Production (Server / VPS)

### Queue Worker (Supervisor)
Create `/etc/supervisor/conf.d/ai-automation-worker.conf`:
```ini
[program:ai-automation-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/ai-automation/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/path/to/ai-automation/storage/logs/worker.log
```
Update and start supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

### Nginx Configuration
Direct your Nginx `root` to `/path/to/ai-automation/public`:
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/ai-automation/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## 🏢 Operating as a SaaS Platform

This platform comes out of the box with multi-tenant workspace architecture:
1. **Workspaces**: Each registered user gets an isolated workspace with its own encryption key, asset library, flow catalog, and MCP servers.
2. **Team Collaboration**: Workspaces support member invitations and role assignments.
3. **Public Form Gateways**: Users can generate public endpoints for their AI workflows (`/f/{slug}`) with rate limiting, allowing them to embed workflows or offer services to clients.
4. **Wholesale Pricing / BYOK**: Since users supply their own keys, hosting costs remain purely for compute and storage.

---

## 🛠️ Tech Stack

- **Backend**: Laravel 12, PHP 8.2+
- **Frontend**: React 19, Inertia.js 2, TypeScript
- **Styling**: Tailwind CSS 4, Radix UI primitives, Lucide Icons
- **Workflow Engine**: Custom DAG execution engine with `@xyflow/react`
- **Database**: MySQL / SQLite via Eloquent ORM
- **Queue**: Database Queue with asynchronous step polling

---

## 📄 License

This project is licensed under the [MIT License](LICENSE). You are free to use, modify, distribute, and monetize this project for personal or commercial purposes.
