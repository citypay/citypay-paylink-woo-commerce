#!/bin/bash

# simply caller the default docker entrypoint which deploys wordpress to the database and boot time. The file is
# called apache2-.. to trigger a condition in the entrypoint script and then call the apache2 script once this is
# complete - sort of a callback process
docker-entrypoint.sh apache2-start.sh

