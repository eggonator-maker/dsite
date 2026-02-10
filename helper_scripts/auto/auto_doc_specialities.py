#!/usr/bin/env python3
"""
Drupal Doctor Specialty Updater Bot
Reads CSV with doctor names and specialties, then updates Drupal content via admin UI
"""

import csv
import time
import sys
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.common.exceptions import TimeoutException, NoSuchElementException


class DrupalDoctorBot:
    def __init__(self, drupal_url, username, password, csv_file):
        self.drupal_url = drupal_url.rstrip('/')
        self.username = username
        self.password = password
        self.csv_file = csv_file
        self.driver = None
        self.wait = None
        
    def setup_driver(self, headless=False):
        """Initialize Chrome WebDriver"""
        options = Options()
        if headless:
            options.add_argument('--headless')
        options.add_argument('--no-sandbox')
        options.add_argument('--disable-dev-shm-usage')
        
        self.driver = webdriver.Chrome(options=options)
        self.wait = WebDriverWait(self.driver, 10)
        
    def login(self):
        """Login to Drupal admin"""
        print(f"Logging in to {self.drupal_url}...")
        self.driver.get(f"{self.drupal_url}/user/login")
        
        username_field = self.wait.until(
            EC.presence_of_element_located((By.ID, "edit-name"))
        )
        username_field.send_keys(self.username)
        
        password_field = self.driver.find_element(By.ID, "edit-pass")
        password_field.send_keys(self.password)
        
        submit_button = self.driver.find_element(By.ID, "edit-submit")
        submit_button.click()
        
        time.sleep(2)
        print("Login successful")
        
    def load_csv_data(self):
        """Load doctor name -> specialty mapping from CSV"""
        doctors = {}
        with open(self.csv_file, 'r', encoding='utf-8') as f:
            reader = csv.DictReader(f)
            for row in reader:
                # Adjust column names based on your CSV structure
                name = row.get('name') or row.get('Name') or row.get('title')
                specialty = row.get('specialty') or row.get('Specialty')
                if name and specialty:
                    # Support multiple specialties separated by pipe |
                    specialties = [s.strip() for s in specialty.split('|')]
                    doctors[name.strip()] = specialties
        
        print(f"Loaded {len(doctors)} doctors from CSV")
        return doctors
        
    def get_doctor_content_list(self):
        """Navigate to doctor content type listing"""
        print("Fetching doctor content list...")
        self.driver.get(f"{self.drupal_url}/admin/content")
        
        # Filter by doctor content type if needed
        # You may need to adjust selectors based on your Drupal setup
        time.sleep(2)
        
    def update_doctor_specialty(self, doctor_name, specialties):
        """Find and update a specific doctor with specialty(ies)"""
        print(f"Processing: {doctor_name} -> {specialties}")
        
        # Search for the doctor
        search_field = self.driver.find_element(By.NAME, "title")
        search_field.clear()
        search_field.send_keys(doctor_name)
        
        # Apply filter
        filter_button = self.driver.find_element(By.ID, "edit-submit-content")
        filter_button.click()
        time.sleep(2)
        
        try:
            # Find the edit link for the matching doctor
            edit_link = self.wait.until(
                EC.presence_of_element_located((By.LINK_TEXT, "Edit"))
            )
            edit_link.click()
            time.sleep(2)
            
            # Add each specialty
            for index, specialty in enumerate(specialties):
                # Find the specialty field for this index
                field_id = f"edit-field-doctors-specialities-{index}-target-id"
                
                try:
                    specialty_field = self.driver.find_element(By.ID, field_id)
                except NoSuchElementException:
                    # Need to add another item - click "Add another item" button
                    add_button = self.driver.find_element(
                        By.NAME, 
                        "field_doctors_specialities_add_more"
                    )
                    add_button.click()
                    time.sleep(1)
                    specialty_field = self.driver.find_element(By.ID, field_id)
                
                specialty_field.clear()
                specialty_field.send_keys(specialty)
                
                # Wait for autocomplete dropdown and select first match
                time.sleep(1)
                try:
                    autocomplete_item = self.wait.until(
                        EC.presence_of_element_located((By.CSS_SELECTOR, ".ui-menu-item"))
                    )
                    autocomplete_item.click()
                except TimeoutException:
                    print(f"  Warning: No autocomplete match found for '{specialty}'")
                
                time.sleep(0.5)
            
            # Save the content
            save_button = self.driver.find_element(By.ID, "edit-submit")
            save_button.click()
            time.sleep(2)
            
            print(f"✓ Updated {doctor_name}")
            return True
            
        except (TimeoutException, NoSuchElementException) as e:
            print(f"✗ Could not find/update {doctor_name}: {e}")
            return False
            
    def run(self):
        """Main execution flow"""
        try:
            self.setup_driver(headless=False)
            self.login()
            
            doctors = self.load_csv_data()
            
            self.get_doctor_content_list()
            
            success_count = 0
            for doctor_name, specialty in doctors.items():
                if self.update_doctor_specialty(doctor_name, specialty):
                    success_count += 1
                    
                # Return to content list
                self.driver.get(f"{self.drupal_url}/admin/content")
                time.sleep(1)
                
            print(f"\n=== Complete ===")
            print(f"Successfully updated: {success_count}/{len(doctors)}")
            
        except Exception as e:
            print(f"Error: {e}")
            raise
        finally:
            if self.driver:
                self.driver.quit()


if __name__ == "__main__":
    if len(sys.argv) < 5:
        print("Usage: python drupal_doctor_updater.py <drupal_url> <username> <password> <csv_file>")
        print("Example: python drupal_doctor_updater.py https://nordspital.ro admin password doctors.csv")
        sys.exit(1)
        
    bot = DrupalDoctorBot(
        drupal_url=sys.argv[1],
        username=sys.argv[2],
        password=sys.argv[3],
        csv_file=sys.argv[4]
    )
    
    bot.run()