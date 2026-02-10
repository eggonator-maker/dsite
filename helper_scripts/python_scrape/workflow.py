#!/usr/bin/env python3
"""
Nord.ro Doctor Scraper - Complete Workflow
==========================================

This script helps you scrape doctor profiles from nord.ro and prepare them for Drupal import.

USAGE WORKFLOW:
--------------
Since direct network access is restricted, you'll need to:

1. Manually fetch the doctors listing page HTML
2. Extract doctor URLs
3. Fetch each doctor profile HTML
4. Parse all profiles
5. Generate Drupal-compatible CSV/JSON

STEP-BY-STEP GUIDE:
------------------

Step 1: Get the doctors listing HTML
   - Visit: https://nord.ro/medici/?locatie=Pitești
   - Save the page source or use web_fetch to get the HTML
   - Save it to: doctors_listing.html

Step 2: Run this script to extract URLs:
   python3 workflow.py extract-urls

Step 3: For each URL, fetch the HTML and save to doctors_html/ directory
   Or provide the HTML content and we'll process it

Step 4: Run this script to parse all profiles:
   python3 workflow.py parse-all

Step 5: Import the generated CSV into Drupal
"""

import os
import sys
import json
from pathlib import Path
from advanced_scraper import (
    DoctorProfileParser, 
    DoctorListingParser,
    save_to_json,
    save_to_drupal_csv
)

class ScraperWorkflow:
    def __init__(self):
        self.output_dir = Path('/home/claude/doctor_data')
        self.html_dir = self.output_dir / 'html_profiles'
        self.output_dir.mkdir(exist_ok=True)
        self.html_dir.mkdir(exist_ok=True)
    
    def extract_urls_from_listing(self, html_file='doctors_listing.html'):
        """Extract doctor URLs from the listing page HTML"""
        print("Extracting doctor URLs from listing page...")
        
        if not os.path.exists(html_file):
            print(f"Error: {html_file} not found!")
            print(f"Please save the HTML from https://nord.ro/medici/?locatie=Pitești")
            print(f"to {html_file}")
            return []
        
        with open(html_file, 'r', encoding='utf-8') as f:
            html_content = f.read()
        
        parser = DoctorListingParser(html_content)
        urls = parser.extract_doctor_urls()
        
        print(f"\nFound {len(urls)} doctor profiles:")
        for i, url in enumerate(urls, 1):
            print(f"  {i}. {url}")
        
        # Save URLs to file
        urls_file = self.output_dir / 'doctor_urls.txt'
        with open(urls_file, 'w') as f:
            f.write('\n'.join(urls))
        
        print(f"\nURLs saved to: {urls_file}")
        print("\nNext steps:")
        print("1. Use web_fetch or browser to get HTML for each URL")
        print(f"2. Save each HTML file to: {self.html_dir}/")
        print("   Name them as: doctor_1.html, doctor_2.html, etc.")
        print("3. Run: python3 workflow.py parse-all")
        
        return urls
    
    def parse_single_html(self, html_file, url):
        """Parse a single doctor profile HTML file"""
        with open(html_file, 'r', encoding='utf-8') as f:
            html_content = f.read()
        
        parser = DoctorProfileParser(html_content, url)
        return parser.parse()
    
    def parse_all_profiles(self):
        """Parse all HTML files in the html_profiles directory"""
        print("Parsing all doctor profiles...")
        
        # Load URLs
        urls_file = self.output_dir / 'doctor_urls.txt'
        if not urls_file.exists():
            print("Error: doctor_urls.txt not found!")
            print("Run: python3 workflow.py extract-urls first")
            return
        
        with open(urls_file, 'r') as f:
            urls = [line.strip() for line in f if line.strip()]
        
        # Find all HTML files
        html_files = sorted(self.html_dir.glob('doctor_*.html'))
        
        if not html_files:
            print(f"Error: No HTML files found in {self.html_dir}/")
            print("Please save doctor profile HTML files as doctor_1.html, doctor_2.html, etc.")
            return
        
        print(f"Found {len(html_files)} HTML files to parse")
        
        doctors = []
        for i, html_file in enumerate(html_files):
            print(f"Parsing {html_file.name}...")
            
            # Try to match with URL
            if i < len(urls):
                url = urls[i]
            else:
                url = f"https://nord.ro/medici/doctor-{i+1}/"
            
            try:
                doctor = self.parse_single_html(html_file, url)
                doctors.append(doctor)
                print(f"  ✓ {doctor.get('name', 'Unknown')}")
            except Exception as e:
                print(f"  ✗ Error: {e}")
        
        print(f"\nSuccessfully parsed {len(doctors)} doctor profiles")
        
        # Save results
        json_file = self.output_dir / 'doctors_complete.json'
        csv_file = self.output_dir / 'doctors_drupal_import.csv'
        
        save_to_json(doctors, str(json_file))
        save_to_drupal_csv(doctors, str(csv_file))
        
        print("\n" + "=" * 60)
        print("SCRAPING COMPLETE!")
        print("=" * 60)
        print(f"\nOutput files:")
        print(f"  JSON: {json_file}")
        print(f"  CSV:  {csv_file}")
        print("\nYou can now import the CSV file into Drupal using:")
        print("  - Feeds module")
        print("  - Migrate module")
        print("  - CSV import module")
        
        return doctors
    
    def add_profile_html(self, html_content, doctor_number):
        """Add a doctor profile HTML to the collection"""
        html_file = self.html_dir / f'doctor_{doctor_number}.html'
        with open(html_file, 'w', encoding='utf-8') as f:
            f.write(html_content)
        print(f"Saved profile to {html_file}")
    
    def create_manual_entry(self):
        """Interactive mode to manually add doctor data"""
        print("\n" + "=" * 60)
        print("MANUAL ENTRY MODE")
        print("=" * 60)
        print("\nPaste the doctor profile HTML (press Ctrl+D when done):")
        
        try:
            html_content = sys.stdin.read()
        except KeyboardInterrupt:
            print("\nCancelled")
            return
        
        # Count existing files
        existing = len(list(self.html_dir.glob('doctor_*.html')))
        next_num = existing + 1
        
        self.add_profile_html(html_content, next_num)
        print(f"\nAdded as doctor_{next_num}.html")
        print(f"Total profiles: {next_num}")
    
    def show_help(self):
        """Show usage instructions"""
        print(__doc__)


def main():
    workflow = ScraperWorkflow()
    
    if len(sys.argv) < 2:
        workflow.show_help()
        return
    
    command = sys.argv[1]
    
    if command == 'extract-urls':
        workflow.extract_urls_from_listing()
    
    elif command == 'parse-all':
        workflow.parse_all_profiles()
    
    elif command == 'add-manual':
        workflow.create_manual_entry()
    
    elif command == 'help':
        workflow.show_help()
    
    else:
        print(f"Unknown command: {command}")
        workflow.show_help()


if __name__ == "__main__":
    main()
