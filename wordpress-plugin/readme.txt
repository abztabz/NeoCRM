=== NeoCRM ===
Contributors: abztabz
Tags: crm, visitor tracking, leads, first party analytics, sales
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later

First-party visitor intelligence and customer relationship management for WordPress.

== Description ==

NeoCRM Release 0.5 provides a continuous human sales journey:

* Permission-based anonymous visitor tracking.
* Sessions, page views, clicks, scroll depth, downloads, and form intent.
* First-touch referral and UTM attribution.
* Engagement scoring and high-intent visitor visibility.
* Public enquiries enter a separate, unverified Lead queue.
* Human qualification and controlled Lead-to-Contact conversion.
* Company records and contact relationship workspaces.
* A six-stage Deals pipeline with value, currency, and win/loss states.
* Follow-up Tasks with priority and due dates.
* Contact notes and automatic CRM activity records.
* Automatic attachment of visitor history to Leads and converted Contacts.
* Unified Sales Funnel: Visitor, Lead, Contacted, Qualified, Proposal, Negotiation, Paying Client, Recurring Client, and Lost.
* Relationships can enter directly at any identified stage while NeoCRM creates the required underlying CRM records.
* Manual Lead entry with source, current stage, estimated value, and recurring value.
* Native Excel `.xlsx` and CSV Lead import with an Excel-ready template, duplicate controls, and import summary.
* Dashboard, Visitors, Sales Funnel, Leads, Contacts, Companies, Deals, Tasks, and Settings screens.
* WordPress personal-data export and erasure integration.
* Configurable consent mode and event retention.

Use `[neocrm_lead_form]` on any page to add the first native lead form.

Release 0.5 can send approved visitor events to NeoCRM Cloud while retaining a local operational mirror. Public lead forwarding is disabled until the separate cloud CRM identity and privacy boundary is activated. Cloud credentials are stored server-side and are never exposed to visitor JavaScript.

== Installation ==

1. Upload the NeoCRM plugin folder or ZIP in WordPress Admin.
2. Activate NeoCRM.
3. Open NeoCRM > Settings and confirm the consent mode.
4. Add `[neocrm_lead_form]` to a page.
5. Visit NeoCRM > Dashboard to review journeys and contacts.

== Changelog ==

= 0.5.0 =
* Adds monetary value, requirements, next follow-up date/time, and Email/Phone/WhatsApp communication medium from the Contacted stage onward.
* Adds stage-specific team notes with author and timestamp history on each client journey.
* Introduces an extensible journey-update record for future meeting minutes, transcripts, and recording references.

= 0.4.2 =
* Makes manual Lead entry and spreadsheet import panels compact and collapsible, with mobile-friendly and accessible controls.

= 0.4.1 =
* Connects successful existing Elementor Pro contact/enquiry forms to NeoCRM without replacing their design or configured actions.
* Attaches the consented visitor journey and stores the submitted message in the Lead timeline.

= 0.4.0 =
* Adds a unified visitor-to-recurring-client lifecycle funnel.
* Allows identified relationships to enter at any sales stage.
* Adds manual Lead intake and native Excel/CSV bulk import.
* Synchronizes lifecycle moves with Leads, Contacts, Companies, and Deals.

= 0.3.0 =
* Adds a real Lead qualification and conversion workflow.
* Adds Companies, Deals pipeline, Tasks, contact workspaces, notes, and CRM activities.
* Prevents public enquiries from overwriting canonical Contacts or granting unverified marketing consent.
* Pins cloud credentials to the approved NeoCRM gateway and fixes first-pageview cloud forwarding.

= 0.2.0 =
* Adds optional NeoCRM Cloud event forwarding with encrypted site credentials and local fallback.

= 0.1.0 =
* Initial build of visitor intelligence, identity conversion, contacts, privacy controls, and dashboard.
