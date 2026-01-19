#!/usr/bin/env python3
"""
Advanced Doctor Scraper for Nord.ro
Uses BeautifulSoup to parse HTML and extract doctor information
Designed to work with the web_fetch tool data
"""

from bs4 import BeautifulSoup
import json
import re
from urllib.parse import urljoin

class DoctorProfileParser:
    """Parser for individual doctor profile pages"""
    
    def __init__(self, html_content, url):
        self.soup = BeautifulSoup(html_content, 'html.parser')
        self.url = url
        self.base_url = "https://nord.ro"
    
    def extract_name(self):
        """Extract doctor's name from the page"""
        # Try multiple selectors
        selectors = [
            'h1',
            '.doctor-name',
            '.profile-name',
            '[class*="name"]',
            'h1.entry-title'
        ]
        
        for selector in selectors:
            elem = self.soup.select_one(selector)
            if elem:
                name = elem.get_text(strip=True)
                # Remove common prefixes
                name = re.sub(r'^(DR\.|Dr\.|PROF\.|Prof\.)\s*', '', name, flags=re.IGNORECASE)
                return name.strip()
        
        return None
    
    def extract_image(self):
        """Extract doctor's profile image URL"""
        # Try to find profile image
        img_patterns = [
            ('img', {'class': re.compile(r'(doctor|medic|profile)', re.I)}),
            ('img', {'src': re.compile(r'(doctor|medic|uploads)', re.I)}),
            ('img', {'alt': re.compile(r'(doctor|medic)', re.I)}),
        ]
        
        for tag, attrs in img_patterns:
            img = self.soup.find(tag, attrs)
            if img and img.get('src'):
                src = img['src']
                # Convert relative URLs to absolute
                if src.startswith('//'):
                    return 'https:' + src
                elif src.startswith('/'):
                    return self.base_url + src
                return src
        
        # Fallback: find first image in content area
        content = self.soup.find('article') or self.soup.find('main') or self.soup
        img = content.find('img')
        if img and img.get('src'):
            src = img['src']
            if src.startswith('//'):
                return 'https:' + src
            elif src.startswith('/'):
                return self.base_url + src
            return src
        
        return None
    
    def extract_specialty(self):
        """Extract doctor's specialty/specialties"""
        # Look for specialty information
        patterns = [
            r'class.*?(specialty|specialitate|specializare)',
            r'id.*?(specialty|specialitate)'
        ]
        
        for pattern in patterns:
            elem = self.soup.find(class_=re.compile(pattern, re.I))
            if not elem:
                elem = self.soup.find(id=re.compile(pattern, re.I))
            
            if elem:
                text = elem.get_text(strip=True)
                # Split by common separators
                specialties = [s.strip() for s in re.split(r'[,;|]', text) if s.strip()]
                return specialties
        
        # Try to find it in structured text
        text = self.soup.get_text()
        specialty_match = re.search(r'Specialitate[:\s]+([^\n]+)', text, re.I)
        if specialty_match:
            return [s.strip() for s in specialty_match.group(1).split(',')]
        
        return []
    
    def extract_location(self):
        """Extract doctor's location/clinic"""
        patterns = [
            r'class.*?(location|locatie|locație|address|adresa)',
            r'id.*?(location|locatie)'
        ]
        
        for pattern in patterns:
            elem = self.soup.find(class_=re.compile(pattern, re.I))
            if not elem:
                elem = self.soup.find(id=re.compile(pattern, re.I))
            
            if elem:
                return elem.get_text(strip=True)
        
        return None
    
    def extract_experience(self):
        """Extract professional experience section"""
        experience = []
        
        # Look for experience header
        headers = self.soup.find_all(['h2', 'h3', 'h4', 'strong'])
        for header in headers:
            text = header.get_text(strip=True)
            if re.search(r'(experien[ţt]|experience|profesional)', text, re.I):
                # Get the next sibling or parent's next content
                parent = header.parent
                
                # Try to find ul/ol list
                exp_list = parent.find_next(['ul', 'ol'])
                if exp_list:
                    items = exp_list.find_all('li')
                    experience = [item.get_text(strip=True) for item in items]
                    break
                
                # Try to find paragraphs
                next_elem = header.find_next_sibling()
                while next_elem and next_elem.name in ['p', 'div']:
                    text = next_elem.get_text(strip=True)
                    if text and not re.search(r'^(educati|formare)', text, re.I):
                        experience.append(text)
                        next_elem = next_elem.find_next_sibling()
                    else:
                        break
                
                if experience:
                    break
        
        return experience
    
    def extract_education(self):
        """Extract education section"""
        education = []
        
        # Look for education header
        headers = self.soup.find_all(['h2', 'h3', 'h4', 'strong'])
        for header in headers:
            text = header.get_text(strip=True)
            if re.search(r'(educa[ţt]|formare|studii)', text, re.I):
                parent = header.parent
                
                # Try to find ul/ol list
                edu_list = parent.find_next(['ul', 'ol'])
                if edu_list:
                    items = edu_list.find_all('li')
                    education = [item.get_text(strip=True) for item in items]
                    break
                
                # Try to find paragraphs
                next_elem = header.find_next_sibling()
                while next_elem and next_elem.name in ['p', 'div']:
                    text = next_elem.get_text(strip=True)
                    if text and not re.search(r'^(experien[ţt])', text, re.I):
                        education.append(text)
                        next_elem = next_elem.find_next_sibling()
                    else:
                        break
                
                if education:
                    break
        
        return education
    
    def extract_description(self):
        """Extract general description/bio"""
        # Try to find main content area
        content_selectors = [
            'article',
            '.entry-content',
            '.doctor-content',
            '.profile-content',
            'main'
        ]
        
        content_area = None
        for selector in content_selectors:
            content_area = self.soup.select_one(selector)
            if content_area:
                break
        
        if not content_area:
            content_area = self.soup
        
        # Get all paragraphs that aren't in experience/education sections
        paragraphs = []
        for p in content_area.find_all('p'):
            text = p.get_text(strip=True)
            # Skip empty paragraphs and those that are clearly section headers
            if text and len(text) > 20 and not re.search(r'^(experien[ţt]|educa[ţt]|formare)', text, re.I):
                paragraphs.append(text)
        
        return '\n\n'.join(paragraphs) if paragraphs else None
    
    def extract_contact(self):
        """Extract contact information"""
        contact = {
            'phone': None,
            'email': None
        }
        
        # Find phone
        phone_link = self.soup.find('a', href=re.compile(r'tel:'))
        if phone_link:
            contact['phone'] = phone_link.get_text(strip=True)
        
        # Find email
        email_link = self.soup.find('a', href=re.compile(r'mailto:'))
        if email_link:
            contact['email'] = email_link.get_text(strip=True)
        
        return contact
    
    def parse(self):
        """Parse all information from the doctor profile"""
        contact = self.extract_contact()
        
        return {
            'url': self.url,
            'name': self.extract_name(),
            'image_url': self.extract_image(),
            'specialty': self.extract_specialty(),
            'location': self.extract_location(),
            'experience': self.extract_experience(),
            'education': self.extract_education(),
            'description': self.extract_description(),
            'phone': contact['phone'],
            'email': contact['email'],
        }


class DoctorListingParser:
    """Parser for the doctors listing page"""
    
    def __init__(self, html_content):
        self.soup = BeautifulSoup(html_content, 'html.parser')
        self.base_url = "https://nord.ro"
    
    def extract_doctor_urls(self):
        """Extract all doctor profile URLs from the listing page"""
        urls = set()
        
        # Find all links that point to /medici/doctor-name/
        links = self.soup.find_all('a', href=re.compile(r'/medici/[^/]+/?$'))
        
        for link in links:
            href = link.get('href')
            if href and href != '/medici/' and href != '/medici':
                # Convert to absolute URL
                if href.startswith('/'):
                    full_url = self.base_url + href
                else:
                    full_url = href
                
                # Ensure it ends with /
                if not full_url.endswith('/'):
                    full_url += '/'
                
                urls.add(full_url)
        
        return sorted(list(urls))


def parse_doctor_profile(html_content, url):
    """Parse a doctor profile page and return structured data"""
    parser = DoctorProfileParser(html_content, url)
    return parser.parse()


def parse_doctors_listing(html_content):
    """Parse the doctors listing page and return list of URLs"""
    parser = DoctorListingParser(html_content)
    return parser.extract_doctor_urls()


def save_to_json(data, filename='doctors.json'):
    """Save data to JSON file"""
    with open(filename, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(f"Saved data to {filename}")


def download_doctor_images(doctors, output_dir='doctor_images'):
    """Download doctor images and save with doctor name as filename"""
    import os
    import requests
    from pathlib import Path
    import re
    
    # Create output directory
    output_path = Path(output_dir)
    output_path.mkdir(exist_ok=True)
    
    print(f"\nDownloading doctor images to {output_dir}/...")
    
    downloaded = []
    failed = []
    
    for doctor in doctors:
        name = doctor.get('name', 'unknown')
        image_url = doctor.get('image_url')
        
        if not image_url:
            print(f"  ⊘ {name}: No image URL")
            continue
        
        # Create safe filename from doctor name
        safe_name = re.sub(r'[^\w\s-]', '', name.lower())
        safe_name = re.sub(r'[-\s]+', '_', safe_name)
        safe_name = safe_name.strip('_')
        
        # Get file extension from URL
        ext = '.jpg'  # default
        if '.' in image_url:
            url_ext = image_url.split('.')[-1].split('?')[0].lower()
            if url_ext in ['jpg', 'jpeg', 'png', 'gif', 'webp']:
                ext = f'.{url_ext}'
        
        filename = f"{safe_name}{ext}"
        filepath = output_path / filename
        
        try:
            # Download image
            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            }
            response = requests.get(image_url, headers=headers, timeout=10)
            response.raise_for_status()
            
            # Save image
            with open(filepath, 'wb') as f:
                f.write(response.content)
            
            # Update doctor data with local filename
            doctor['image_filename'] = filename
            downloaded.append(name)
            print(f"  ✓ {name} → {filename}")
            
        except Exception as e:
            failed.append((name, str(e)))
            print(f"  ✗ {name}: {e}")
    
    print(f"\nImage download complete:")
    print(f"  Downloaded: {len(downloaded)}")
    print(f"  Failed: {len(failed)}")
    
    if failed:
        print("\nFailed downloads:")
        for name, error in failed:
            print(f"  • {name}: {error}")
    
    return downloaded, failed


def save_to_drupal_csv(doctors, filename='doctors_import.csv', include_image_path=True):
    """Save data in CSV format for Drupal import"""
    import csv
    
    fieldnames = ['name', 'specialty', 'location', 'image_url', 'image_filename',
                  'experience', 'education', 'description', 'phone', 'email', 'source_url']
    
    with open(filename, 'w', encoding='utf-8', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        
        writer.writeheader()
        for doctor in doctors:
            # Flatten arrays to strings
            row = {
                'name': doctor.get('name', ''),
                'specialty': ', '.join(doctor.get('specialty', [])),
                'location': doctor.get('location', ''),
                'image_url': doctor.get('image_url', ''),
                'image_filename': doctor.get('image_filename', ''),
                'experience': ' | '.join(doctor.get('experience', [])),
                'education': ' | '.join(doctor.get('education', [])),
                'description': doctor.get('description', ''),
                'phone': doctor.get('phone', ''),
                'email': doctor.get('email', ''),
                'source_url': doctor.get('url', ''),
            }
            writer.writerow(row)
    
    print(f"Saved Drupal import CSV to {filename}")


# Test with sample HTML
if __name__ == "__main__":
    # Example usage
    sample_html = """
    <html>
    <body>
        <h1>DR. GUIȚĂ ALEXANDRU GIGEL</h1>
        <div class="specialty">ATI</div>
        <img src="/uploads/doctor.jpg" alt="Doctor">
        <div class="experience">
            <h3>Experiență profesională</h3>
            <p>2022 – Prezent: Medic Primar ATI, Nord Muntenia Hospital</p>
        </div>
    </body>
    </html>
    """
    
    parser = DoctorProfileParser(sample_html, "https://nord.ro/medici/test/")
    result = parser.parse()
    print(json.dumps(result, ensure_ascii=False, indent=2))
