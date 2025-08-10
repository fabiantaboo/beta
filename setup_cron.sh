#!/bin/bash

# Setup script for Ayuni Beta Proactive Messaging Cron Jobs
# Run this script to automatically configure cron jobs for the proactive messaging system

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}Ayuni Beta - Proactive Messaging Cron Setup${NC}"
echo "============================================="

# Get the absolute path to the project
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR"

echo "Project directory: $PROJECT_DIR"

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo -e "${RED}Error: PHP is not installed or not in PATH${NC}"
    exit 1
fi

PHP_PATH=$(which php)
echo "PHP path: $PHP_PATH"

# Check if the worker script exists
WORKER_SCRIPT="$PROJECT_DIR/cron/proactive_worker.php"
if [ ! -f "$WORKER_SCRIPT" ]; then
    echo -e "${RED}Error: Worker script not found at $WORKER_SCRIPT${NC}"
    exit 1
fi

# Test the worker script
echo -e "${YELLOW}Testing worker script...${NC}"
if $PHP_PATH "$WORKER_SCRIPT" --max_jobs=1 --verbose 2>&1; then
    echo -e "${GREEN}✓ Worker script test successful${NC}"
else
    echo -e "${RED}✗ Worker script test failed${NC}"
    echo "Please check your database configuration and permissions"
    exit 1
fi

# Prepare cron entries
CRON_ENTRIES="
# Ayuni Beta Proactive Messaging System
# Process proactive messages every 10 minutes
*/10 * * * * $PHP_PATH $WORKER_SCRIPT >> /var/log/ayuni_proactive.log 2>&1

# Cleanup and maintenance once daily at 2 AM
0 2 * * * $PHP_PATH $WORKER_SCRIPT --max_jobs=5 --schedule_new=false >> /var/log/ayuni_maintenance.log 2>&1
"

echo -e "${YELLOW}Cron entries to be added:${NC}"
echo "$CRON_ENTRIES"

# Ask user for confirmation
read -p "Do you want to add these cron entries? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 0
fi

# Create log directory
sudo mkdir -p /var/log/
sudo touch /var/log/ayuni_proactive.log
sudo touch /var/log/ayuni_maintenance.log
sudo chmod 664 /var/log/ayuni_proactive.log
sudo chmod 664 /var/log/ayuni_maintenance.log

# Add to user's crontab
(crontab -l 2>/dev/null; echo "$CRON_ENTRIES") | crontab -

echo -e "${GREEN}✓ Cron jobs added successfully!${NC}"
echo
echo "The following cron jobs are now active:"
echo "- Proactive message processing every 10 minutes"
echo "- Daily maintenance at 2 AM"
echo
echo "Log files:"
echo "- /var/log/ayuni_proactive.log (main processing)"
echo "- /var/log/ayuni_maintenance.log (cleanup and maintenance)"
echo
echo "To view current cron jobs: crontab -l"
echo "To remove cron jobs: crontab -e (then delete the Ayuni Beta lines)"
echo
echo "To monitor the logs in real-time:"
echo "  tail -f /var/log/ayuni_proactive.log"
echo
echo -e "${GREEN}Setup complete! Your AEIs will now be able to send proactive messages.${NC}"