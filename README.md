# WPFlat Core

The foundation of the WPFlat suite: clients and contacts, team, departments, groups, roles, settings, activity, notifications, messages, the client portal, backup and restore, stealth mode, the app shell and the module registry that the other WPFlat plugins plug into.



Part of the **WPFlat** suite (WPFlat Core, WPFlat Tasks, WPFlat Finance, WPFlat Invoice, WPFlat Sign, WPFlat Files). Each plugin has its own repository and installs on its own; the modules need WPFlat Core.

## Install

Download or clone this repository, put the `wpflatcore` folder in `wp-content/plugins/`, and activate it (activate WPFlat Core first). The built scripts in `build/` are committed, so nothing needs compiling to use it.

## Develop

```
npm install
npm run build      # rebuilds build/ from ui/
```

PHP lives in `includes/`, the Vue source in `ui/`. The shared test suites and deploy tools live in the WPFlat workspace (`wpflat-suite/` next to this folder).

License: GPL-2.0-or-later.
