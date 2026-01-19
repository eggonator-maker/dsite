#!/usr/bin/env python3
"""
Generate web_fetch commands for scraping doctors
This script helps create the commands needed to fetch all doctor profiles
"""

import json

# Known doctor URLs to start with
KNOWN_DOCTORS = [
    "https://nord.ro/medici/dr-guita-alexandru-gigel/",
]

# Common doctor name patterns at Nord Pitești (you can add more as you discover them)
PITESTI_DOCTORS = [
    "dr-guita-alexandru-gigel",
    # Add more doctor slugs here as you find them
]

def generate_fetch_instructions():
    """Generate instructions for fetching all doctors"""
    
    print("=" * 70)
    print("DOCTOR SCRAPING INSTRUCTIONS FOR CLAUDE")
    print("=" * 70)
    
    print("\n📋 STEP 1: Get the list of all doctors")
    print("-" * 70)
    print("Ask Claude to search for doctors at Nord Pitești:")
    print('   "Can you search for all doctors at Nord Pitești on nord.ro?"')
    print("\nOr visit: https://nord.ro/medici/?locatie=Pitești")
    
    print("\n📋 STEP 2: Fetch each doctor profile")
    print("-" * 70)
    print("For each doctor URL found, use web_fetch to get the HTML.")
    print("\nExample commands to give Claude:")
    print("")
    
    for i, url in enumerate(KNOWN_DOCTORS, 1):
        print(f'   Doctor {i}: web_fetch("{url}")')
    
    print("\n📋 STEP 3: Process the fetched HTML")
    print("-" * 70)
    print("Save each fetched HTML to files and process them with:")
    print("   python3 workflow.py parse-all")
    
    print("\n📋 STEP 4: Import to Drupal")
    print("-" * 70)
    print("Use the generated CSV file to import into Drupal 11")
    print("")


def create_batch_script():
    """Create a bash script template for batch processing"""
    
    script = """#!/bin/bash
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
"""
    
    with open('/home/claude/batch_scrape.sh', 'w') as f:
        f.write(script)
    
    print("Created batch_scrape.sh template")


def generate_drupal_import_guide():
    """Generate a guide for importing into Drupal"""
    
    guide = """
DRUPAL 11 IMPORT GUIDE
======================

The scraper generates a CSV file with the following columns:
- name
- specialty
- location
- image_url
- experience
- education
- description
- phone
- email
- source_url

METHOD 1: Using Feeds Module
-----------------------------
1. Install Feeds module: composer require drupal/feeds
2. Enable the module: drush en feeds
3. Create a new Feed Type at /admin/structure/feeds
4. Configure:
   - Content type: Your doctor content type
   - Parser: CSV
   - Mapping: Map CSV columns to your fields
5. Import at /import

METHOD 2: Using Migrate Module
-------------------------------
1. Create a custom migration configuration
2. Use migrate_source_csv as the source plugin
3. Map fields in the migration YAML
4. Run: drush migrate:import your_migration_name

METHOD 3: Custom Import Script
-------------------------------
Use the JSON output and create a custom Drush command or PHP script
to programmatically create nodes.

EXAMPLE MIGRATION YAML:
-----------------------
id: nord_doctors
label: Import Nord Doctors
migration_group: nord_content

source:
  plugin: csv
  path: /path/to/doctors_drupal_import.csv
  header_row_count: 1
  keys:
    - name

process:
  title: name
  field_specialty: specialty
  field_location: location
  field_experience: experience
  field_education: education
  body: description
  field_phone: phone
  field_email: email
  field_source_url: source_url
  
  # For image, you'll need to download it first
  field_image:
    plugin: image_import
    source: image_url

destination:
  plugin: entity:node
  default_bundle: doctor

DOWNLOADING IMAGES:
------------------
The image_url column contains URLs. You'll need to:
1. Download each image
2. Save to Drupal's files directory
3. Create file entities
4. Associate with the doctor nodes

You can use a custom process plugin or download images separately.
"""
    
    with open('/home/claude/DRUPAL_IMPORT_GUIDE.txt', 'w') as f:
        f.write(guide)
    
    print("Created DRUPAL_IMPORT_GUIDE.txt")


if __name__ == "__main__":
    generate_fetch_instructions()
    print("\n")
    create_batch_script()
    print("")
    generate_drupal_import_guide()
    
    print("\n" + "=" * 70)
    print("✅ Helper files created:")
    print("   - batch_scrape.sh (template)")
    print("   - DRUPAL_IMPORT_GUIDE.txt")
    print("=" * 70)
