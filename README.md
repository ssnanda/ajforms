# AJ Core

AJ Core provides reusable WordPress business functionality. AJNanda and other presentation layers consume its public interfaces.

## Reviews & Testimonials

The **AJ Core → Reviews & Testimonials** area manages Google Business Profile OAuth, temporary review synchronization, business-selected featured reviews, and independent permanent manual testimonials. Google reviews refresh every 28 days and expire after 28½ days. Nothing is featured automatically.

See [setup, architecture, security, retention, and developer interfaces](docs/reviews-testimonials.md), and the [implementation and verification report](docs/reviews-implementation-report.md). The feature requires an approved Google Business Profile API project for live synchronization; manual testimonials work independently.

Existing release versions remain unchanged until the normal release workflow. Release scripts can commit, push, publish, and deploy; they are not test commands.
