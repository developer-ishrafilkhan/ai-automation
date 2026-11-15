<?php

/*
| One-click connectors. Each app is a remote MCP server that supports OAuth sign-in.
|
| Apps without "oauth" settings use the MCP authorization spec: endpoints are discovered
| from the server and the client registers itself (dynamic client registration).
| Apps whose provider doesn't allow self-registration (Google, GitHub) use an OAuth
| client you create once and put in .env. Its redirect URI must be:
|   {APP_URL}/connectors/oauth/callback
*/

$google = fn (string $product, array $scopes) => [
    'client' => 'google',
    'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_endpoint' => 'https://oauth2.googleapis.com/token',
    'scopes' => array_map(fn ($s) => "https://www.googleapis.com/auth/{$s}", $scopes),
    // Needed for a refresh token, so runs keep working after the first hour.
    'extra_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
];

$googleNote = 'Google\'s MCP servers are in the Workspace Developer Preview: your Google Cloud project must be in that program, with a Web OAuth client marked "Use this client for an AI-powered agent".';

return [
    'clients' => [
        'google' => [
            'client_id' => env('GOOGLE_MCP_CLIENT_ID'),
            'client_secret' => env('GOOGLE_MCP_CLIENT_SECRET'),
            'env' => 'GOOGLE_MCP_CLIENT_ID / GOOGLE_MCP_CLIENT_SECRET',
        ],
        'github' => [
            'client_id' => env('GITHUB_MCP_CLIENT_ID'),
            'client_secret' => env('GITHUB_MCP_CLIENT_SECRET'),
            'env' => 'GITHUB_MCP_CLIENT_ID / GITHUB_MCP_CLIENT_SECRET',
        ],
        // Slack and HubSpot discover fine but don't allow dynamic client registration.
        'slack' => [
            'client_id' => env('SLACK_MCP_CLIENT_ID'),
            'client_secret' => env('SLACK_MCP_CLIENT_SECRET'),
            'env' => 'SLACK_MCP_CLIENT_ID / SLACK_MCP_CLIENT_SECRET',
        ],
        'hubspot' => [
            'client_id' => env('HUBSPOT_MCP_CLIENT_ID'),
            'client_secret' => env('HUBSPOT_MCP_CLIENT_SECRET'),
            'env' => 'HUBSPOT_MCP_CLIENT_ID / HUBSPOT_MCP_CLIENT_SECRET',
        ],
    ],

    'apps' => [
        ['id' => 'gmail', 'name' => 'Gmail', 'category' => 'Google Workspace', 'color' => '#EA4335',
            'description' => 'Search and read email threads, manage labels, create drafts.',
            'url' => 'https://gmailmcp.googleapis.com/mcp/v1', 'oauth' => $google('gmail', ['gmail.readonly', 'gmail.compose']), 'note' => $googleNote],
        ['id' => 'google-sheets', 'name' => 'Google Sheets', 'category' => 'Google Workspace', 'color' => '#0F9D58',
            'description' => 'Read and update spreadsheets, values and formulas.',
            'url' => 'https://sheetsmcp.googleapis.com/mcp/v1', 'oauth' => $google('sheets', ['drive.readonly', 'drive.file', 'spreadsheets.readonly', 'spreadsheets']), 'note' => $googleNote],
        ['id' => 'google-drive', 'name' => 'Google Drive', 'category' => 'Google Workspace', 'color' => '#4285F4',
            'description' => 'Search and read files in Drive.',
            'url' => 'https://drivemcp.googleapis.com/mcp/v1', 'oauth' => $google('drive', ['drive.readonly', 'drive.file']), 'note' => $googleNote],
        ['id' => 'google-docs', 'name' => 'Google Docs', 'category' => 'Google Workspace', 'color' => '#4285F4',
            'description' => 'Read and edit documents.',
            'url' => 'https://docsmcp.googleapis.com/mcp/v1', 'oauth' => $google('docs', ['drive.readonly', 'drive.file', 'documents.readonly', 'documents']), 'note' => $googleNote],
        ['id' => 'google-calendar', 'name' => 'Google Calendar', 'category' => 'Google Workspace', 'color' => '#1A73E8',
            'description' => 'List calendars and events, check free/busy.',
            'url' => 'https://calendarmcp.googleapis.com/mcp/v1', 'oauth' => $google('calendar', ['calendar.calendarlist.readonly', 'calendar.events.freebusy', 'calendar.events.readonly']), 'note' => $googleNote],
        ['id' => 'google-slides', 'name' => 'Google Slides', 'category' => 'Google Workspace', 'color' => '#F4B400',
            'description' => 'Read and edit presentations.',
            'url' => 'https://slidesmcp.googleapis.com/mcp/v1', 'oauth' => $google('slides', ['drive.readonly', 'drive.file', 'presentations.readonly', 'presentations']), 'note' => $googleNote],

        ['id' => 'notion', 'name' => 'Notion', 'category' => 'Docs & knowledge', 'color' => '#111111',
            'description' => 'Search, read and write pages and databases.',
            'url' => 'https://mcp.notion.com/mcp'],
        ['id' => 'github', 'name' => 'GitHub', 'category' => 'Engineering', 'color' => '#24292F',
            'description' => 'Repositories, issues, pull requests and code search.',
            'url' => 'https://api.githubcopilot.com/mcp/',
            'oauth' => [
                'client' => 'github',
                'authorization_endpoint' => 'https://github.com/login/oauth/authorize',
                'token_endpoint' => 'https://github.com/login/oauth/access_token',
                'scopes' => ['repo', 'read:org', 'read:user'],
            ],
            'note' => 'Uses a GitHub OAuth app you register once. You can also paste a personal access token in "Custom server".'],
        ['id' => 'slack', 'name' => 'Slack', 'category' => 'Communication', 'color' => '#4A154B',
            'description' => 'Search messages and channels, read threads.',
            'url' => 'https://mcp.slack.com/mcp', 'oauth' => ['client' => 'slack'],
            'note' => 'Uses a Slack app you register once (Slack does not allow automatic client registration).'],
        ['id' => 'linear', 'name' => 'Linear', 'category' => 'Engineering', 'color' => '#5E6AD2',
            'description' => 'Issues, projects and cycles.',
            'url' => 'https://mcp.linear.app/mcp'],
        ['id' => 'atlassian', 'name' => 'Jira & Confluence', 'category' => 'Docs & knowledge', 'color' => '#0052CC',
            'description' => 'Jira issues and Confluence pages (Atlassian Cloud).',
            'url' => 'https://mcp.atlassian.com/v2/mcp'],
        ['id' => 'hubspot', 'name' => 'HubSpot', 'category' => 'Sales & CRM', 'color' => '#FF7A59',
            'description' => 'Contacts, companies, deals and tickets.',
            'url' => 'https://mcp.hubspot.com', 'oauth' => ['client' => 'hubspot'],
            'note' => 'Uses a HubSpot app you register once (HubSpot does not allow automatic client registration).'],
        ['id' => 'stripe', 'name' => 'Stripe', 'category' => 'Payments', 'color' => '#635BFF',
            'description' => 'Customers, payments, invoices and products.',
            'url' => 'https://mcp.stripe.com'],
    ],
];
