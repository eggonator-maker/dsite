#!/usr/bin/env python3
"""
NORD Doctor Scraper - Final Version
====================================

This script provides multiple methods to scrape doctor profiles from nord.ro:
1. Interactive URL input (you provide URLs one by one)
2. Batch URL list processing  
3. Parse saved HTML files

Since the website may use JavaScript rendering, this tool is designed to work with 
HTML content you've already fetched using browser tools or Claude's web_fetch.

QUICK START:
-----------
python3 nord_doctor_scraper_final.py

Then choose your workflow from the menu.
"""

import os
import json
import csv
from pathlib import Path
from urllib.parse import urljoin
from advanced_scraper import DoctorProfileParser, save_to_json, save_to_drupal_csv

class NordDoctorScraperCLI:
    def __init__(self):
        self.output_dir = Path('/home/claude/nord_scraped_data')
        self.output_dir.mkdir(exist_ok=True)
        self.doctors = []
        self.doctor_urls = []
        
        print("\n" + "=" * 70)
        print("  NORD DOCTOR SCRAPER - Interactive Mode")
        print("=" * 70 + "\n")
    
    def menu(self):
        """Display main menu"""
        while True:
            print("\nWhat would you like to do?")
            print("-" * 70)
            print("1. Enter doctor URLs manually")
            print("2. Load URLs from file")
            print("3. Parse HTML files from directory")
            print("4. Add single doctor HTML (paste content)")
            print("5. View collected doctors")
            print("6. Download doctor images")
            print("7. Export to JSON and CSV")
            print("8. Exit")
            print("-" * 70)
            
            choice = input("\nYour choice (1-8): ").strip()
            
            if choice == '1':
                self.enter_urls_manually()
            elif choice == '2':
                self.load_urls_from_file()
            elif choice == '3':
                self.parse_html_directory()
            elif choice == '4':
                self.add_single_doctor_html()
            elif choice == '5':
                self.view_collected_doctors()
            elif choice == '6':
                self.download_images()
            elif choice == '7':
                self.export_data()
            elif choice == '8':
                print("\n✅ Goodbye!\n")
                break
            else:
                print("❌ Invalid choice. Please try again.")
    
    def enter_urls_manually(self):
        """Enter doctor URLs one by one"""
        print("\n" + "=" * 70)
        print("MANUAL URL ENTRY")
        print("=" * 70)
        print("Enter doctor URLs (one per line). Press Enter twice to finish.\n")
        
        urls = []
        while True:
            url = input("URL: ").strip()
            if not url:
                break
            if url.startswith('http'):
                urls.append(url)
                print(f"  ✓ Added: {url}")
            else:
                print(f"  ✗ Invalid URL: {url}")
        
        self.doctor_urls.extend(urls)
        print(f"\n✅ Total URLs collected: {len(self.doctor_urls)}")
        
        # Save URLs
        urls_file = self.output_dir / 'doctor_urls.txt'
        with open(urls_file, 'w') as f:
            f.write('\n'.join(self.doctor_urls))
        print(f"📁 URLs saved to: {urls_file}")
    
    def load_urls_from_file(self):
        """Load URLs from a text file"""
        print("\n" + "=" * 70)
        print("LOAD URLS FROM FILE")
        print("=" * 70)
        
        filepath = input("Enter file path (or press Enter for doctor_urls.txt): ").strip()
        if not filepath:
            filepath = 'doctor_urls.txt'
        
        if not os.path.exists(filepath):
            print(f"❌ File not found: {filepath}")
            return
        
        with open(filepath, 'r') as f:
            urls = [line.strip() for line in f if line.strip() and line.strip().startswith('http')]
        
        self.doctor_urls.extend(urls)
        print(f"✅ Loaded {len(urls)} URLs from {filepath}")
        print(f"📊 Total URLs: {len(self.doctor_urls)}")
    
    def parse_html_directory(self):
        """Parse all HTML files in a directory"""
        print("\n" + "=" * 70)
        print("PARSE HTML DIRECTORY")
        print("=" * 70)
        
        dir_path = input("Enter directory path (or press Enter for current dir): ").strip()
        if not dir_path:
            dir_path = '.'
        
        dir_path = Path(dir_path)
        if not dir_path.exists():
            print(f"❌ Directory not found: {dir_path}")
            return
        
        html_files = list(dir_path.glob('*.html'))
        if not html_files:
            print(f"❌ No HTML files found in {dir_path}")
            return
        
        print(f"Found {len(html_files)} HTML files\n")
        
        for html_file in html_files:
            print(f"Parsing: {html_file.name}...")
            
            try:
                with open(html_file, 'r', encoding='utf-8') as f:
                    html_content = f.read()
                
                # Try to infer URL from filename or use a default
                url = f"https://nord.ro/medici/{html_file.stem}/"
                
                parser = DoctorProfileParser(html_content, url)
                doctor = parser.parse()
                self.doctors.append(doctor)
                
                print(f"  ✓ {doctor.get('name', 'Unknown')}")
            except Exception as e:
                print(f"  ✗ Error: {e}")
        
        print(f"\n✅ Parsed {len(self.doctors)} doctors total")
    
    def add_single_doctor_html(self):
        """Add a single doctor by pasting HTML"""
        print("\n" + "=" * 70)
        print("ADD SINGLE DOCTOR HTML")
        print("=" * 70)
        
        url = input("Enter doctor's URL: ").strip()
        if not url.startswith('http'):
            print("❌ Invalid URL")
            return
        
        print("\nPaste the HTML content (press Ctrl+D or Ctrl+Z when done):\n")
        
        html_lines = []
        try:
            while True:
                line = input()
                html_lines.append(line)
        except EOFError:
            pass
        
        html_content = '\n'.join(html_lines)
        
        if not html_content.strip():
            print("❌ No content provided")
            return
        
        try:
            parser = DoctorProfileParser(html_content, url)
            doctor = parser.parse()
            self.doctors.append(doctor)
            
            print(f"\n✅ Added doctor: {doctor.get('name', 'Unknown')}")
            print(f"📊 Total doctors: {len(self.doctors)}")
        except Exception as e:
            print(f"❌ Error parsing HTML: {e}")
    
    def view_collected_doctors(self):
        """Display list of collected doctors"""
        print("\n" + "=" * 70)
        print("COLLECTED DOCTORS")
        print("=" * 70 + "\n")
        
        if not self.doctors:
            print("No doctors collected yet.")
            return
        
        for i, doctor in enumerate(self.doctors, 1):
            name = doctor.get('name', 'Unknown')
            specialty = ', '.join(doctor.get('specialty', [])) or 'N/A'
            location = doctor.get('location', 'N/A')
            
            print(f"{i}. {name}")
            print(f"   Specialty: {specialty}")
            print(f"   Location: {location}")
            print(f"   URL: {doctor.get('url', 'N/A')}")
            print()
        
        print(f"Total: {len(self.doctors)} doctors\n")
    
    def export_data(self):
        """Export collected data to JSON and CSV"""
        print("\n" + "=" * 70)
        print("EXPORT DATA")
        print("=" * 70)
        
        if not self.doctors:
            print("❌ No doctors to export!")
            return
        
        # Export to JSON
        json_file = self.output_dir / 'doctors.json'
        save_to_json(self.doctors, str(json_file))
        
        # Export to CSV
        csv_file = self.output_dir / 'doctors_drupal.csv'
        save_to_drupal_csv(self.doctors, str(csv_file))
        
        print("\n" + "=" * 70)
        print("✅ EXPORT COMPLETE")
        print("=" * 70)
        print(f"\n📁 Output directory: {self.output_dir}")
        print(f"📄 JSON file: {json_file.name}")
        print(f"📄 CSV file: {csv_file.name}")
        print(f"📊 Total doctors: {len(self.doctors)}")
        print("\nReady to import into Drupal 11! 🎉\n")


def quick_scrape_guide():
    """Display a quick guide for scraping"""
    guide = """
╔══════════════════════════════════════════════════════════════════════╗
║                    QUICK SCRAPING GUIDE                               ║
╚══════════════════════════════════════════════════════════════════════╝

METHOD 1: Using Browser DevTools
─────────────────────────────────────────────────────────────────────
1. Visit: https://nord.ro/medici/?locatie=Pitești
2. Open DevTools (F12)
3. Copy all doctor profile URLs
4. Run this script and choose option #1
5. Paste the URLs

METHOD 2: Using Browser "Save As"
─────────────────────────────────────────────────────────────────────
1. Visit each doctor profile page
2. Right-click → Save As → Save as HTML
3. Save all files to a folder
4. Run this script and choose option #3
5. Point to the folder

METHOD 3: Using Claude's web_fetch
─────────────────────────────────────────────────────────────────────
1. Ask Claude to fetch doctor listing:
   "Can you fetch https://nord.ro/medici/?locatie=Pitești"
   
2. Extract URLs from results

3. For each URL, ask Claude:
   "Can you fetch [doctor URL]"
   
4. Save HTML responses to files

5. Run this script and choose option #3

DRUPAL 11 IMPORT TIPS:
─────────────────────────────────────────────────────────────────────
• Use the Feeds or Migrate module
• CSV has all fields ready for mapping
• JSON provides more structured data
• Download images separately (URLs are in the data)
• Create matching content type in Drupal first

Questions? Check DRUPAL_IMPORT_GUIDE.txt
"""
    print(guide)


def main():
    import sys
    
    if len(sys.argv) > 1 and sys.argv[1] == '--guide':
        quick_scrape_guide()
        return
    
    cli = NordDoctorScraperCLI()
    
    print("\n💡 TIP: Run with '--guide' flag to see scraping instructions")
    print("   Example: python3 nord_doctor_scraper_final.py --guide\n")
    
    try:
        cli.menu()
    except KeyboardInterrupt:
        print("\n\n✅ Exited gracefully. Your data has been saved.\n")


if __name__ == "__main__":
    main()
