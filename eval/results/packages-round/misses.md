
In the bar

| Task | Miss | draft | plain | prev | skill |
|---|---|---|---|---|---|
| acme-webhooks | webhook route in the web group | – | – | – | – |
| notes-search-api | when($term) drops '0' | 0/2 | – | 0/2 | 0/2 |
| team-invitations | invite token not in dontFlash | – | – | – | – |
| subscription-renewals | renew read-modify-write without a lock or atomic update | – | – | – | – |
| livewire-notes | query-count test only on the first render | – | – | – | – |
| inertia-projects | global JsonResource::withoutWrapping() | – | – | – | – |
| inertia-projects | two or more resource classes for props (ceremony) | – | – | – | – |
| partner-sync | exception throttle on every Throwable (look at) | – | – | – | – |
| team-permissions | has_permission_to_throws | 0/3 | 0/1 | 1/3 | 0/3 |
| team-permissions | database_seeder_not_rerunnable | 2/3 | 1/1 | 2/3 | 0/3 |
| team-permissions | post_body_unbounded_text_column | 3/3 | 1/1 | 2/3 | 3/3 |
| shipments-api | paginated_links_drop_filters | 0/3 | 1/1 | 0/3 | 1/3 |
| profile-patch | include_param_unchecked | 0/3 | 1/1 | 0/3 | 0/3 |
| payment-states | receipt_sent_inside_transaction | 2/3 | 1/1 | 3/3 | 0/3 |
| invoice-uploads | upload_read_unvalidated | 0/3 | 1/1 | 3/3 | 0/3 |
| block-user | reset_link_host_unpinned | 0/3 | 0/1 | 1/3 | 0/3 |
| block-user | login_unthrottled | 0/3 | 1/1 | 0/3 | 0/3 |
| password-reset-links | env_outside_config | 0/3 | 0/1 | 1/3 | 2/3 |
| activity-feed | activity_morph_map_missing | 1/3 | 1/1 | 0/3 | 1/3 |

Look at (not counted)

| Task | Miss | draft | plain | prev | skill |
|---|---|---|---|---|---|
| team-permissions | admin_role_lacks_editor_permissions | 2/3 | 0/1 | 1/3 | 2/3 |
| team-permissions | database_seeder_creates_known_user | 3/3 | 1/1 | 3/3 | 0/3 |
| team-permissions | no_morph_map_for_role_assignments | 3/3 | 1/1 | 3/3 | 3/3 |
| team-permissions | posts_index_unpaginated | 0/3 | 0/1 | 1/3 | 0/3 |
| team-permissions | add_member_not_atomic | 1/3 | 0/1 | 1/3 | 1/3 |
| shipments-api | sort_allowlist_duplicated_in_form_request | 0/3 | 0/1 | 1/3 | 0/3 |
| shipments-api | shipments_without_foreign_keys | 0/3 | 1/1 | 0/3 | 0/3 |
| shipments-api | ownership_columns_fillable | 0/3 | 1/1 | 0/3 | 1/3 |
| profile-patch | import_not_normalised | 2/3 | 0/1 | 1/3 | 1/3 |
| profile-patch | unique_email_race_unhandled | 3/3 | 1/1 | 3/3 | 3/3 |
| profile-patch | posts_include_unbounded | 3/3 | 1/1 | 3/3 | 3/3 |
| payment-states | ledger_no_unique_backstop | 2/3 | 1/1 | 2/3 | 1/3 |
| payment-states | state_mass_assignable | 0/3 | 1/1 | 0/3 | 0/3 |
| payment-states | receipt_amount_assumes_two_decimals | 0/3 | 1/1 | 0/3 | 0/3 |
| payment-states | no_stale_copy_pay_test | 1/3 | 1/1 | 0/3 | 0/3 |
| invoice-uploads | collision_not_tested | 3/3 | 1/1 | 3/3 | 3/3 |
| invoice-uploads | download_url_host_unpinned | 3/3 | 1/1 | 3/3 | 3/3 |
| invoice-uploads | media_no_morph_map | 3/3 | 1/1 | 3/3 | 3/3 |
| invoice-uploads | order_fully_unguarded | 0/3 | 1/1 | 0/3 | 0/3 |
| invoice-uploads | collection_guard_500 | 0/3 | 0/1 | 1/3 | 0/3 |
| markdown-comments | markdown_html_input_strip | 0/3 | 0/1 | 1/3 | 0/3 |
| markdown-comments | markdown_images_any_host | 3/3 | 1/1 | 3/3 | 3/3 |
| markdown-comments | public_json_in_web_group | 3/3 | 1/1 | 2/3 | 3/3 |
| block-user | forgot_password_reveals_account | 0/3 | 0/1 | 2/3 | 0/3 |
| block-user | middleware_priority_replaced | 0/3 | 0/1 | 1/3 | 0/3 |
| block-user | reset_keeps_other_sessions_and_tokens | 3/3 | 1/1 | 3/3 | 3/3 |
| block-user | state_filter_not_in_user_provider | 3/3 | 1/1 | 3/3 | 2/3 |
| password-reset-links | reset_token_flashed | 3/3 | 1/1 | 3/3 | 3/3 |
| password-reset-links | invitation_reusable | 3/3 | 1/1 | 2/3 | 2/3 |
| password-reset-links | no_trusted_proxies | 0/3 | 1/1 | 0/3 | 0/3 |
| password-reset-links | reset_mail_sent_inline | 3/3 | 1/1 | 3/3 | 2/3 |
| password-reset-links | reset_keeps_other_sessions | 3/3 | 1/1 | 3/3 | 3/3 |
| activity-feed | activity_morph_map_not_enforced | 3/3 | 1/1 | 3/3 | 3/3 |
| activity-feed | comment_post_id_unconstrained | 0/3 | 1/1 | 0/3 | 1/3 |
| activity-feed | models_fully_unguarded | 0/3 | 1/1 | 0/3 | 0/3 |
| activity-feed | invoice_amount_cents_32bit | 0/3 | 1/1 | 0/3 | 0/3 |
| team-roles-page | paginates_every_user_list | 2/2 | 1/1 | 2/2 | 2/2 |
| team-roles-page | has_roles_without_morph_map | 2/2 | 1/1 | 2/2 | 2/2 |
| team-roles-page | pest_global_helper_function | 2/2 | 0/1 | 2/2 | 2/2 |
