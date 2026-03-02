from __future__ import annotations

from playwright.sync_api import sync_playwright

from .config import get_settings


def main() -> None:
    settings = get_settings()

    with sync_playwright() as p:
        context = p.chromium.launch_persistent_context(
            user_data_dir=settings.profile_dir,
            headless=False,
            viewport={"width": 1400, "height": 1000},
        )

        try:
            page = context.new_page()
            page.goto("https://www.linkedin.com/login", wait_until="domcontentloaded")
            input("Zaloguj się ręcznie, potem naciśnij ENTER w terminalu...")
            page.goto("https://www.linkedin.com/feed/", wait_until="domcontentloaded")
            print(f"Sesja zapisana. Aktualny URL: {page.url}")
        finally:
            context.close()


if __name__ == "__main__":
    main()
