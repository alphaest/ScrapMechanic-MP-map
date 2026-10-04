# Scrap Mechanic Multiplayer Map

Using https://github.com/the1killer/sm_overview

## Features

- All changes synced across every user live (5 sec poll)
- Drop premade or custom (emoticons) markers on the map
- Measure distances using multileg ruler
- Quickly go to any player pin by clicking on their name
- Mark markers as complete
- Meant to be used locally or privately hosted. No authorization


## Requirements

Webserver with PHP support.
File writing permissions in the deployment folder.

## Quick start

Using https://github.com/the1killer/sm_overview or https://sm.kornplays.com/
generate a map and download it using "Local asset source".
Copy the content of that zip into a php supported web host folder.
Clone the contents of this repo and push it on top of it.

If the webhosts app server doesn't have write access to your files, change the following permissions:
- active-players.json - 777
- done-markers.json - 777
- done-markers.lock - 777
- markers.json - 777
- markers.lock - 777
- pins.json - 777
- pins.lock - 777

