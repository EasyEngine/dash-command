# easyengine/dash-command

This package provides the `ee dash` command, allowing you to integrate your EasyEngine server and sites with the web dashboard.

## Description

The `ee dash` command facilitates the connection between your server and web dashboard. It securely sends server and site-specific data to your web dashboard organization, enabling you to manage and monitor your EasyEngine sites from a centralized dashboard.

The primary command is `ee dash init`, which performs the following actions:
-   Verifies server compatibility (Ubuntu 22.04 or later).
-   Adds the web dashboard SSH key for secure communication.
-   Registers the server with your specified web dashboard organization.
-   Syncs all existing EasyEngine sites to web dashboard, sending relevant details like PHP version, SSL status, and more.

## Installation

This command is bundled with EasyEngine. If you have a standard EasyEngine installation, no additional installation steps are required.

## Usage

To integrate your server with web dashboard, run the following command:

```bash
ee dash init --api=<your-api-key> --org=<your-org-name>
```

This step is no longer be necessary manually. Once you register your server, the web dashboard will automatically fire this command to sync your sites.

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.
