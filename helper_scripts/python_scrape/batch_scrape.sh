#!/bin/bash
# Batch script for scraping Nord doctors
# This is a TEMPLATE - you'll need to customize it

echo "Nord Doctor Scraper"
echo "=================="

# Create output directory
mkdir -p /home/claude/doctor_data/html_profiles

# Fetch doctor listing page
echo "Step 1: Fetching doctor listing..."
# curl -s "https://nord.ro/medici/?locatie=Pitești" > /home/claude/doctors_listing.html

# Extract URLs
echo "Step 2: Extracting doctor URLs..."
python3 /home/claude/workflow.py extract-urls

# Read URLs and fetch each profile
echo "Step 3: Fetching individual profiles..."
urls_file="/home/claude/doctor_data/doctor_urls.txt"

if [ -f "$urls_file" ]; then
    i=1
    while IFS= read -r url; do
        echo "Fetching doctor $i: $url"
        # curl -s "$url" > "/home/claude/doctor_data/html_profiles/doctor_${i}.html"
        # sleep 1  # Be nice to the server
        i=$((i+1))
    done < "$urls_file"
fi

# Parse all profiles
echo "Step 4: Parsing all profiles..."
python3 /home/claude/workflow.py parse-all

echo ""
echo "Done! Check /home/claude/doctor_data/ for results"
