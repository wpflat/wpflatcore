# BizyCore

The foundation of the Bizy suite: clients and contacts, team, departments, groups, roles, settings, activity, notifications, messages, the client portal, backup and restore, stealth mode, the app shell and the module registry that the other Bizy plugins plug into.



Part of the **Bizy** suite (BizyCore, BizyTasks, BizyMoney, BizyDoc, BizyFiles). Each plugin has its own repository and installs on its own; the modules need BizyCore.

## Install

Download or clone this repository, put the `bizycore` folder in `wp-content/plugins/`, and activate it (activate BizyCore first). The built scripts in `build/` are committed, so nothing needs compiling to use it.

## Develop

```
npm install
npm run build      # rebuilds build/ from ui/
```

PHP lives in `includes/`, the Vue source in `ui/`. The shared test suites and deploy tools live in the Bizy workspace (`bizy/` next to this folder).

License: GPL-2.0-or-later.
