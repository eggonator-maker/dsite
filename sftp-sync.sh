#!/bin/bash

# === CONFIGURATION ===
SITE="nord-staging"
ENV="dev"

# Helper to detect directories automatically
detect_local_config() {
  if [ -d "web/sites/default/files/sync" ]; then echo "web/sites/default/files/sync"; 
  elif [ -d "config/sync" ]; then echo "config/sync"; 
  else echo "config"; fi
}

LOCAL_CONFIG_DIR=$(detect_local_config)
REMOTE_DEFAULT_PATH="code/config" 

# === SSH HELPER FUNCTIONS ===

setup_ssh_agent() {
  if ssh-add -l &>/dev/null; then
    echo "✅ SSH agent already running with keys loaded"
    return 0
  fi
  
  if [ -n "$SSH_AUTH_SOCK" ] && ! ssh-add -l &>/dev/null; then
    echo "⚠️  Stale SSH agent detected, cleaning up..."
    unset SSH_AUTH_SOCK
    unset SSH_AGENT_PID
  fi
  
  echo "Starting SSH agent..."
  eval "$(ssh-agent -s)"
  
  echo "Adding SSH key..."
  ssh-add ~/.ssh/id_rsa
  
  if [ $? -ne 0 ]; then
    echo "❌ Failed to add SSH key. Check path ~/.ssh/id_rsa"
    exit 1
  fi
  
  trap "ssh-agent -k" EXIT
  echo "✅ SSH key added successfully"
}

setup_ssh_controlmaster() {
  mkdir -p ~/.ssh/cm
  
  # Check if proper config exists (appserver rule must come first!)
  if ! grep -q "Host appserver.\*.drush.in" ~/.ssh/config 2>/dev/null; then
    echo "Setting up SSH ControlMaster..."
    
    # Backup existing config
    cp ~/.ssh/config ~/.ssh/config.backup.$(date +%s) 2>/dev/null || true
    
    # Remove any old Pantheon ControlMaster configs
    sed -i.tmp '/# Pantheon ControlMaster/,/ServerAliveCountMax/d' ~/.ssh/config 2>/dev/null || true
    
    cat >> ~/.ssh/config << 'EOF'
# Pantheon SSH - appserver MUST BE FIRST (most specific rule)
Host appserver.*.drush.in
  ControlMaster no
  ControlPath none
  ServerAliveInterval 60
  ServerAliveCountMax 10
  StrictHostKeyChecking no

# Pantheon SSH - general drush.in (less specific)
Host *.drush.in
  ControlMaster auto
  ControlPath ~/.ssh/cm/%C
  ControlPersist 5m
  ServerAliveInterval 60
  ServerAliveCountMax 10
  StrictHostKeyChecking no
EOF
    echo "✅ SSH ControlMaster configured"
  fi
}

cleanup_ssh_controlmaster() {
  # Kill any stale connections before terminus drush commands
  echo "Cleaning up stale SSH connections..."
  rm -rf ~/.ssh/cm/* 2>/dev/null || true
}

# === MAIN LOGIC ===

setup_ssh_agent
setup_ssh_controlmaster

echo "Getting site connection info..."
CONN_INFO=$(terminus connection:info $SITE.$ENV --field=sftp_username 2>/dev/null)
if [ -z "$CONN_INFO" ]; then
  echo "❌ Error: Could not verify Pantheon connection. Run 'terminus auth:login' first."
  exit 1
fi
SITE_ID=$(echo $CONN_INFO | cut -d. -f2)

echo ""
echo "=== Pantheon Development Workflow ==="
echo "Target: $SITE.$ENV"
echo "Local Config Dir: $LOCAL_CONFIG_DIR"
echo "-----------------------------------"
echo "1. ⬇️  Pull Content (DB + Files) -> Apply LOCAL Config"
echo "2. ⬆️  Push Work (Config + Code) -> To Pantheon"
echo "3. 🔄 Re-Import Local Config"
echo "4. 📂 Push Files Only"
echo "5. 🔍 Diagnostics (Check Remote Files & Logs)"
echo "6. 📜 Pull Logs"
echo "-----------------------------------"
read -p "Choose option (1-6): " OPTION

case $OPTION in
  1)
    echo ""
    echo "=== ⬇️  Pulling Content from Pantheon ==="
    
    # Database
    echo ">> Pulling Database..."
    terminus backup:create $SITE.$ENV --element=database
    terminus backup:get $SITE.$ENV --element=database --to=staging-db.sql.gz
    ddev import-db --file=staging-db.sql.gz
    rm staging-db.sql.gz
    
    # Files
    echo ">> Pulling Files..."
    rsync -rlvz --size-only --ipv4 \
      -e 'ssh -p 2222' \
      --exclude='styles/*' \
      --exclude='css/*' \
      --exclude='js/*' \
      --exclude='php/*' \
      $ENV.$SITE_ID@appserver.$ENV.$SITE_ID.drush.in:files/ \
      web/sites/default/files/
    
    echo ">> Applying Local Config/Schema to imported Database..."
    ddev drush updatedb -y
    ddev drush cr
    
    echo "✅ Pull complete!"
    ;;
    
  2)
    echo ""
    echo "=== ⬆️  Pushing Changes to Pantheon ==="
    echo "ℹ️  Note: Pantheon manages vendor/ and contrib/ via Composer"
    
    echo ">> Exporting Local Config..."
    ddev drush config:export -y
    
    echo ">> Switching to SFTP mode..."
    terminus connection:set $SITE.$ENV sftp
    
    echo ">> Pushing Custom Modules..."
    rsync -rlvz --delete --ipv4 \
      -e 'ssh -p 2222' \
      --exclude='node_modules/' \
      --exclude='*.git*' \
      web/modules/custom/ \
      $ENV.$SITE_ID@appserver.$ENV.$SITE_ID.drush.in:code/web/modules/custom/
      
    echo ">> Pushing Custom Themes..."
    rsync -rlvz --delete --ipv4 \
      -e 'ssh -p 2222' \
      --exclude='node_modules/' \
      --exclude='*.git*' \
      web/themes/custom/ \
      $ENV.$SITE_ID@appserver.$ENV.$SITE_ID.drush.in:code/web/themes/custom/

    # Get remote config directory and normalize it
    REMOTE_CONFIG_DIR=$(terminus drush $SITE.$ENV -- core:status --fields=config-sync --format=string 2>/dev/null)  

    # Normalize the path for SSH/rsync
    case "$REMOTE_CONFIG_DIR" in
    "../config")
        REMOTE_CONFIG_DIR="code/config"
        ;;
    "config")
        REMOTE_CONFIG_DIR="code/web/config"
        ;;
    "sites/default/files/sync")
        REMOTE_CONFIG_DIR="code/web/sites/default/files/sync"
        ;;
    "")
        REMOTE_CONFIG_DIR="code/config"  # Default for modern Drupal
        ;;
    *)
        # If already starts with code/, use as-is, otherwise prepend code/web/
        if [[ "$REMOTE_CONFIG_DIR" != "code/"* ]]; then
        REMOTE_CONFIG_DIR="code/web/$REMOTE_CONFIG_DIR"
        fi
        ;;
    esac

    echo ">> Pushing Config to $REMOTE_CONFIG_DIR..."
    ssh -p 2222 $ENV.$SITE_ID@appserver.$ENV.$SITE_ID.drush.in "mkdir -p $REMOTE_CONFIG_DIR"
    
    rsync -rlvz --delete --ipv4 \
      -e 'ssh -p 2222' \
      $LOCAL_CONFIG_DIR/ \
      $ENV.$SITE_ID@appserver.$ENV.$SITE_ID.drush.in:$REMOTE_CONFIG_DIR/
      
    echo ">> Committing changes..."
    read -p "Enter commit message: " COMMIT_MSG
    [ -z "$COMMIT_MSG" ] && COMMIT_MSG="Deploy code and config from local"
    terminus env:commit $SITE.$ENV --message="$COMMIT_MSG" --force
    
    echo ">> Waiting for Pantheon to build (composer install runs automatically)..."
   
    
    # Clean up SSH connections before terminus drush commands
    cleanup_ssh_controlmaster
    
    echo ">> Clearing cache before config import..."
    terminus drush $SITE.$ENV -- cr
    if [ $? -ne 0 ]; then
      echo "⚠️  Cache clear had issues. Continuing anyway..."
    fi
    
    echo ">> Running database updates..."
    UPDATE_OUTPUT=$(terminus drush $SITE.$ENV -- updatedb -y 2>&1)
    UPDATE_EXIT=$?
    echo "$UPDATE_OUTPUT"
    if [ $UPDATE_EXIT -ne 0 ]; then
      echo "❌ Database updates failed!"
      mkdir -p logs
      echo "$UPDATE_OUTPUT" > logs/last-updatedb-error.log
      echo "Error saved to logs/last-updatedb-error.log"
      echo ""
      echo "Recent Drupal errors:"
      terminus drush $SITE.$ENV -- watchdog:show --severity=Error --count=10
      exit 1
    fi
    
    echo ">> Importing Config on Pantheon..."
    CONFIG_OUTPUT=$(terminus drush $SITE.$ENV -- config:import -y 2>&1)
    CONFIG_EXIT=$?
    echo "$CONFIG_OUTPUT"
    if [ $CONFIG_EXIT -ne 0 ]; then
      echo "❌ Config import failed!"
      mkdir -p logs
      echo "$CONFIG_OUTPUT" > logs/last-config-import-error.log
      echo "Error saved to logs/last-config-import-error.log"
      echo ""
      echo "Config status:"
      terminus drush $SITE.$ENV -- config:status
      echo ""
      echo "Recent Drupal errors:"
      terminus drush $SITE.$ENV -- watchdog:show --severity=Error --count=10
      exit 1
    fi
    
    echo ">> Final cache clear..."
    terminus drush $SITE.$ENV -- cr
    
    echo "✅ Push complete!"
    echo ""
    echo "Verify at: https://dev-nord-staging.pantheonsite.io"
    ;;

  3)
    echo ">> Re-importing local config..."
    ddev drush config:import -y
    ddev drush cr
    echo "✅ Done."
    ;;

  4)
    echo ">> Pushing files only..."
    rsync -rlvz --size-only --ipv4 \
      -e 'ssh -p 2222' \
      --exclude='styles/*' \
      --exclude='css/*' \
      --exclude='js/*' \
      --exclude='php/*' \
      web/sites/default/files/ \
      $ENV.$SITE_ID@appserver.$ENV.$SITE_ID.drush.in:files/
    
    cleanup_ssh_controlmaster
    terminus drush $SITE.$ENV -- cr
    echo "✅ Done."
    ;;

  5)
    echo "=== 🔍 Diagnostics ==="
    
    echo ""
    echo ">> Checking Config Status..."
    cleanup_ssh_controlmaster
    terminus drush $SITE.$ENV -- config:status
    
    echo ""
    echo ">> Checking for Disabled Modules..."
    terminus drush $SITE.$ENV -- pm:list --type=module --status=disabled --format=table
    
    echo ""
    echo ">> Recent Errors (last 20)..."
    terminus drush $SITE.$ENV -- watchdog:show --severity=Error --count=20
    
    echo ""
    echo ">> Recent PHP Errors (last 10)..."
    terminus drush $SITE.$ENV -- watchdog:show --type=php --count=10
    ;;

  6)
    echo "=== 📜 Pulling Log Files from Pantheon ==="
    mkdir -p logs
    
    # Pull file system logs
    rsync -rlvz --size-only --ipv4 \
      -e 'ssh -p 2222' \
      $ENV.$SITE_ID@appserver.$ENV.$SITE_ID.drush.in:logs/ \
      logs/
    
    # Pull Drupal watchdog logs
    cleanup_ssh_controlmaster
    echo ">> Fetching recent Drupal errors..."
    terminus drush $SITE.$ENV -- watchdog:show --type=php --count=100 > logs/drupal-php-errors.log
    terminus drush $SITE.$ENV -- watchdog:show --severity=Error --count=100 > logs/drupal-errors.log
    terminus drush $SITE.$ENV -- watchdog:show --count=200 > logs/drupal-all-recent.log
    
    echo "✅ Logs downloaded to logs/ folder"
    echo "   - logs/php-error.log (Pantheon PHP errors)"
    echo "   - logs/nginx-error.log (Pantheon nginx errors)"
    echo "   - logs/drupal-php-errors.log (Drupal PHP watchdog)"
    echo "   - logs/drupal-errors.log (Drupal error watchdog)"
    echo "   - logs/drupal-all-recent.log (All recent Drupal logs)"
    ;;
    
  *)
    echo "Invalid Option"
    exit 1
    ;;
esac