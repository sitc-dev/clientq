#!/usr/bin/env python3
"""Register Roadtest without a logo and open its waitlist and owner staff portal.

Install scripts/requirements-selenium.txt, then run this script with Chrome
installed. Credentials are saved outside the repository with mode 0600.
Use --login-only to reopen an already provisioned tenant using that file.
"""

import argparse
import json
import os
from pathlib import Path
import secrets
from urllib.parse import urlsplit, urlunsplit

from selenium import webdriver
from selenium.common.exceptions import StaleElementReferenceException
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import Select, WebDriverWait


BUSINESS_NAME = "Roadtest Barbershop"
SLUG = "roadtest"


def parse_args():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--platform-url", default="http://clientq.local:8080")
    parser.add_argument("--email", default="roadtest-owner@example.com")
    parser.add_argument("--phone", default="2025550199")
    parser.add_argument("--plan", choices=("starter", "growth", "pro"), default="growth")
    parser.add_argument(
        "--credentials-file",
        type=Path,
        default=Path.home() / ".local/state/clientq/roadtest-owner.json",
    )
    parser.add_argument("--login-only", action="store_true")
    parser.add_argument("--headless", action="store_true", help="Verify without leaving a browser open.")
    args = parser.parse_args()
    platform = urlsplit(args.platform_url)
    if (
        platform.scheme not in ("http", "https")
        or not platform.hostname
        or platform.username
        or platform.password
        or platform.path not in ("", "/")
        or platform.query
        or platform.fragment
    ):
        parser.error("--platform-url must be a platform origin, such as http://clientq.local:8080")
    if not args.phone.isascii() or not args.phone.isdigit() or len(args.phone) != 10:
        parser.error("--phone must contain exactly 10 digits")
    args.platform_url = args.platform_url.rstrip("/")
    args.email = args.email.strip().lower()
    return args


def wait_for_result(driver, wait, success):
    def outcome(browser):
        if success(browser):
            return True
        for message in browser.find_elements(By.ID, "message"):
            if message.is_displayed() and message.text.strip():
                raise RuntimeError(message.text.strip())
        return False

    wait.until(outcome)


def fill(driver, selector, value):
    field = driver.find_element(By.CSS_SELECTOR, selector)
    field.clear()
    field.send_keys(value)


def main():
    args = parse_args()
    platform = urlsplit(args.platform_url)
    tenant_host = f"{SLUG}.{platform.hostname}"
    if platform.port is not None:
        tenant_host += f":{platform.port}"
    tenant_url = urlunsplit((platform.scheme, tenant_host, "/", "", ""))
    login_url = tenant_url + "admin/login"
    credentials_path = args.credentials_file.expanduser().resolve()

    if credentials_path.exists():
        credentials = json.loads(credentials_path.read_text())
        if credentials["platform_url"] != args.platform_url or credentials["username"] != args.email:
            raise RuntimeError("The credentials file belongs to a different server or username.")
        print(f"Using credentials stored in {credentials_path}", flush=True)
    elif args.login_only:
        raise RuntimeError(f"No saved credentials found at {credentials_path}")
    else:
        credentials = {
            "platform_url": args.platform_url,
            "business": BUSINESS_NAME,
            "owner": "Roadtest Owner",
            "username": args.email,
            "password": secrets.token_urlsafe(24),
            "plan": args.plan,
            "waitlist_url": tenant_url,
            "staff_login_url": login_url,
        }
        credentials_path.parent.mkdir(parents=True, exist_ok=True)
        descriptor = os.open(credentials_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "w") as credential_file:
            json.dump(credentials, credential_file, indent=2)
            credential_file.write("\n")
        print(f"Credentials saved to {credentials_path}", flush=True)

    options = webdriver.ChromeOptions()
    options.add_argument("--window-size=1440,1000")
    options.add_experimental_option("detach", not args.headless)
    if args.headless:
        options.add_argument("--headless=new")
    driver = webdriver.Chrome(options=options)
    driver.set_page_load_timeout(60)
    wait = WebDriverWait(driver, 90, ignored_exceptions=(StaleElementReferenceException,))
    completed = False

    try:
        if not args.login_only:
            driver.get(args.platform_url + "/signup")
            wait.until(EC.visibility_of_element_located((By.ID, "signupForm")))
            Select(driver.find_element(By.ID, "plan")).select_by_value(args.plan)
            for selector, value in {
                "#businessName": BUSINESS_NAME,
                "#slug": SLUG,
                "#firstName": "Roadtest",
                "#lastName": "Owner",
                "#email": args.email,
                "#phone": args.phone,
                "#password": credentials["password"],
            }.items():
                fill(driver, selector, value)
            if driver.find_element(By.ID, "logo").get_attribute("value"):
                raise RuntimeError("The logo field must be empty.")
            wait.until(EC.element_to_be_clickable((By.ID, "submitButton"))).click()
            wait_for_result(
                driver, wait,
                lambda browser: urlsplit(browser.current_url).path == "/signup-confirmation",
            )
            wait.until(EC.visibility_of_element_located((By.ID, "reviewPanel")))
            if driver.find_elements(By.CSS_SELECTOR, 'img[src*="/api/pending-business-logo"]'):
                raise RuntimeError("Signup unexpectedly includes a logo.")
            wait.until(EC.element_to_be_clickable((By.ID, "confirmButton"))).click()
            wait_for_result(
                driver, wait,
                lambda browser: browser.find_element(By.ID, "successPanel").is_displayed(),
            )
            actual_login_url = driver.find_element(By.ID, "loginLink").get_attribute("href")
            if actual_login_url != login_url:
                raise RuntimeError(f"Unexpected tenant login URL: {actual_login_url}")
            print("Roadtest tenant and owner provisioned successfully.", flush=True)

        driver.get(tenant_url)
        wait.until(EC.text_to_be_present_in_element((By.TAG_NAME, "h1"), BUSINESS_NAME))
        wait.until(EC.element_to_be_clickable((By.ID, "waitlistTab"))).click()
        wait.until(EC.visibility_of_element_located((By.ID, "clientList")))
        wait.until(
            lambda browser: "Loading..." not in browser.find_element(By.ID, "clientList").text
        )
        if driver.find_elements(By.CSS_SELECTOR, "#clientList [role='alert']"):
            raise RuntimeError("The public waitlist could not load its clients.")
        if driver.find_elements(By.CSS_SELECTOR, f'img[alt="{BUSINESS_NAME} logo"]'):
            raise RuntimeError("Roadtest unexpectedly has a logo.")

        driver.switch_to.new_window("tab")
        driver.get(login_url)
        wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "input[name='email']")))
        fill(driver, "input[name='email']", credentials["username"])
        fill(driver, "#password", credentials["password"])
        driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()

        def logged_in(browser):
            if urlsplit(browser.current_url).path == "/admin/dashboard":
                return True
            for message in browser.find_elements(By.CSS_SELECTOR, ".bg-red-100"):
                if message.is_displayed() and message.text.strip():
                    raise RuntimeError(message.text.strip())
            return False

        wait.until(logged_in)
        wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "nav a[href='staff']")))
        wait.until(
            lambda browser: "Loading..." not in browser.find_element(By.ID, "clientListContainer").text
        )
        if driver.find_elements(By.CSS_SELECTOR, "#clientListContainer [role='alert']"):
            raise RuntimeError("The staff portal could not load its clients.")
        completed = True
        print(f"Waitlist: {tenant_url}", flush=True)
        print(f"Staff login: {login_url}", flush=True)
        print(f"Email: {credentials['username']}", flush=True)
        print(f"Password: stored in {credentials_path}", flush=True)
        print("Verified owner access.", flush=True)
        if not args.headless:
            address = driver.capabilities["goog:chromeOptions"]["debuggerAddress"]
            print(f"Chrome debugging address: {address}", flush=True)
            print("Leaving the waitlist and logged-in staff portal open in Chrome.", flush=True)
    finally:
        if completed and not args.headless:
            driver.service.stop()
        else:
            driver.quit()


if __name__ == "__main__":
    main()
