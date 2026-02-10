#!/usr/bin/env python3
"""
Scraper for Nord.ro doctors
Extracts doctor profiles from listing pages and individual profile pages
"""

import requests
from bs4 import BeautifulSoup
import json
import time
from urllib.parse import urljoin
import re

class NordDoctorScraper:
    def __init__(self):
        self.base_url = "https://nord.ro"
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'ro-RO,ro;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding': 'gzip, deflate, br',
            'Connection': 'keep-alive',
        })
        
    def get_doctors_list(self, location="Pitești"):
        """Get list of doctor profile URLs from the listing page"""
        url = f"{self.base_url}/medici/"
        params = {'locatie': location} if location else {}
        
        try:
            response = self.session.get(url, params=params, timeout=10)
            response.raise_for_status()
            soup = BeautifulSoup(response.content, 'html.parser')
            
            # Find all doctor profile links
            doctor_links = []
            
            # Look for doctor cards/links - adjust selector based on actual HTML
            # Common patterns: class containing 'doctor', 'medic', or links to /medici/
            links = soup.find_all('a', href=re.compile(r'/medici/[^/]+/?$'))
            
            for link in links:
                href = link.get('href')
                if href and href != '/medici/':
                    full_url = urljoin(self.base_url, href)
                    if full_url not in doctor_links:
                        doctor_links.append(full_url)
            
            print(f"Found {len(doctor_links)} doctor profiles")
            return doctor_links
            
        except Exception as e:
            print(f"Error fetching doctors list: {e}")
            return []
    
    def scrape_doctor_profile(self, url):
        """Scrape individual doctor profile page"""
        try:
            response = self.session.get(url, timeout=10)
            response.raise_for_status()
            soup = BeautifulSoup(response.content, 'html.parser')
            
            doctor_data = {
                'url': url,
                'name': None,
                'title': None,
                'specialties': [],
                'location': None,
                'image_url': None,
                'description': None,
                'education': [],
                'experience': [],
                'languages': [],
                'phone': None,
                'email': None,
            }
            
            # Extract name (usually in h1 or with class containing 'name', 'nume')
            name_elem = soup.find('h1') or soup.find(class_=re.compile(r'(name|nume|doctor)', re.I))
            if name_elem:
                doctor_data['name'] = name_elem.get_text(strip=True)
            
            # Extract image
            img = soup.find('img', class_=re.compile(r'(doctor|medic|profile)', re.I))
            if not img:
                img = soup.find('img', src=re.compile(r'(doctor|medic|uploads)', re.I))
            if img and img.get('src'):
                doctor_data['image_url'] = urljoin(self.base_url, img['src'])
            
            # Extract specialties
            specialty_elem = soup.find(class_=re.compile(r'(specialty|specialitate|specializare)', re.I))
            if specialty_elem:
                specialties_text = specialty_elem.get_text(strip=True)
                doctor_data['specialties'] = [s.strip() for s in specialties_text.split(',')]
            
            # Extract location
            location_elem = soup.find(class_=re.compile(r'(location|locatie|address)', re.I))
            if location_elem:
                doctor_data['location'] = location_elem.get_text(strip=True)
            
            # Extract description/bio
            desc_elem = soup.find(class_=re.compile(r'(description|desc|bio|despre)', re.I))
            if not desc_elem:
                # Try to find main content area
                desc_elem = soup.find('div', class_=re.compile(r'(content|main|entry)', re.I))
            if desc_elem:
                # Get all paragraphs
                paragraphs = desc_elem.find_all('p')
                if paragraphs:
                    doctor_data['description'] = '\n\n'.join(p.get_text(strip=True) for p in paragraphs if p.get_text(strip=True))
                else:
                    doctor_data['description'] = desc_elem.get_text(strip=True)
            
            # Extract contact info
            phone_elem = soup.find('a', href=re.compile(r'tel:'))
            if phone_elem:
                doctor_data['phone'] = phone_elem.get_text(strip=True)
            
            email_elem = soup.find('a', href=re.compile(r'mailto:'))
            if email_elem:
                doctor_data['email'] = email_elem.get_text(strip=True)
            
            print(f"Scraped: {doctor_data['name']}")
            return doctor_data
            
        except Exception as e:
            print(f"Error scraping {url}: {e}")
            return None
    
    def scrape_all_doctors(self, location="Pitești", delay=1):
        """Scrape all doctors from a location"""
        doctor_urls = self.get_doctors_list(location)
        all_doctors = []
        
        for i, url in enumerate(doctor_urls, 1):
            print(f"\nScraping {i}/{len(doctor_urls)}: {url}")
            doctor_data = self.scrape_doctor_profile(url)
            if doctor_data:
                all_doctors.append(doctor_data)
            
            # Be respectful - add delay between requests
            if i < len(doctor_urls):
                time.sleep(delay)
        
        return all_doctors
    
    def save_to_json(self, doctors, filename='doctors_data.json'):
        """Save scraped data to JSON file"""
        with open(filename, 'w', encoding='utf-8') as f:
            json.dump(doctors, f, ensure_ascii=False, indent=2)
        print(f"\nSaved {len(doctors)} doctors to {filename}")


def main():
    scraper = NordDoctorScraper()
    
    # Test with a single profile first
    print("Testing with single profile...")
    test_url = "https://nord.ro/medici/dr-guita-alexandru-gigel/"
    test_data = scraper.scrape_doctor_profile(test_url)
    
    if test_data:
        print("\nTest successful! Doctor data:")
        print(json.dumps(test_data, ensure_ascii=False, indent=2))
        
        # Ask if user wants to continue with full scrape
        print("\n" + "="*50)
        print("Would you like to scrape all doctors from Pitești?")
        print("This will be done in the next step.")
    else:
        print("Test failed. Please check the HTML structure.")
    
    # Uncomment to scrape all doctors
    # doctors = scraper.scrape_all_doctors(location="Pitești")
    # scraper.save_to_json(doctors)


if __name__ == "__main__":
    main()
