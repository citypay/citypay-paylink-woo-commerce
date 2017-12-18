#!/bin/bash

# File is used to deploy from a git source location to Wordpress SVN
# Run ./deploy.sh on the latest branch that is ready for deployment
# and ensure tags are correct on src/readme.txt and ./readme.md.
# The script will check for them

# file creates credentials for deploying to wp
source .wp-credentials.conf
echo $USER
PLUGINPATH=src
MAINFILE=wc-payment-gateway-citypay.php
PROJECT=citypay-payments
SVNURL=https://plugins.svn.wordpress.org/$PROJECT
SVNPATH=wp-release

echo
echo "Project: $PROJECT"
echo "Source: $PLUGINPATH"
echo "Mainfile: $MAINFILE"

VERSION=`grep -E "Version: " "$PLUGINPATH/$MAINFILE" | awk -F' ' '{print $2}'`
README="$PLUGINPATH/readme.txt"
RVERSION=`grep "^Stable tag" "$README" | awk -F' ' '{print $3}'`

echo "MAINFILE version: $VERSION"
echo "README   version: $RVERSION"

if [ "$VERSION" != "$RVERSION" ]; then
	echo >&2 "ERROR: readme.txt version does not match version in $MAINFILE."
	exit 1
fi

echo "Checking out SVN repository in $SVNPATH..."
svn checkout $SVNURL $SVNPATH

echo "Setting svn:ignore for git and release specific files..."
svn propset svn:ignore "
README.md
.git*
" "$SVNPATH/trunk/"


echo
echo "Exporting git HEAD of master to SVN trunk..."

# Delete existing files to capture deleted files.
find $SVNPATH/trunk/ -type f -exec rm '{}' ';'
# Export current files.
cp -R $PLUGINPATH/* $SVNPATH/trunk/
cp $PLUGINPATH/assets/* $SVNPATH/assets/

echo
echo "Exporting to SVN..."
pushd $SVNPATH > /dev/null
pushd trunk > /dev/null

# Delete missing/deleted files.
svn status | grep "^!" | awk '{gsub(":\\\\", "/", $2); gsub("\\\\", "/", $2); print $2}' | xargs svn delete
# Add new files.
# svn:ignore files are not listed.
svn status | grep "^?" | awk '{gsub(":\\\\", "/", $2); gsub("\\\\", "/", $2); print $2}' | xargs svn add

# Extra safety net; SVN does not allow to rewrite history.
echo
echo "Changes to be committed to SVN:"
svn status
echo
echo -e "Ready to publish release to SVN? (Y/n) [n] \c"
read CONFIRMED
[[ $CONFIRMED == "Y" ]] || { echo "Aborted."; exit 1; }

svn commit --username "$USER" --password "$PASS" -m "Preparing release $VERSION."

echo
echo "Creating and committing SVN tag..."
popd > /dev/null
svn copy trunk tags/$VERSION
pushd tags/$VERSION > /dev/null
svn commit  --username "$USER" --password "$PASS" -m "Tagging release $VERSION."
popd > /dev/null

echo "Done."
popd > /dev/null

