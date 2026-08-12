# NeoCRM

NeoCRM is the relationship layer of the living website: a professional WordPress CRM that begins with consented anonymous visitor activity and continues through lead identification and customer management.

## Release 0.1 scope

- First-party visitor and session tracking
- Consent modes and retention controls
- Referral and UTM attribution
- Engagement scoring
- Anonymous-to-contact identity conversion
- Native lead-capture shortcode
- Visitors, contacts, dashboard, and settings screens
- WordPress privacy export and erasure integration

## Install

Package this repository as a ZIP, install it through **Plugins > Add New > Upload Plugin**, activate it, and place `[neocrm_lead_form]` on a page.

## Product boundary

NeoCRM does not attempt to identify anonymous people, use browser fingerprinting, or capture sensitive fields. It records first-party events only under the configured consent model.

## Roadmap

Release 0.2 adds visitor timelines and richer contact profiles. Later releases add companies, deals, tasks, automation, forms, and living-website intelligence.
