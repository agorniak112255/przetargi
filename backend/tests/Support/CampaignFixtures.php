<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\ErpCustomer;
use App\Models\ErpCustomerItem;
use App\Models\ErpItem;
use App\Models\MailingList;
use App\Models\MailingListContact;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\UserMailerFactory;

/** Dane testowe kampanii: nadawca ze skrzynką, towary XL, karty, grupy, klienci XL. XL niczego nie pobiera. */
trait CampaignFixtures
{
    protected FakeCampaignMailerFactory $mailers;

    private int $fixtureGid = 1000;

    protected function setUpCampaigns(): void
    {
        config(['campaigns.public_url' => 'https://przetargi.example.pl', 'campaigns.company_name' => 'SUPON']);
        $this->mailers = new FakeCampaignMailerFactory;
        $this->app->instance(UserMailerFactory::class, $this->mailers);
    }

    /** @param  array<string, mixed>  $account */
    protected function sender(array $account = [], ?string $operator = null): User
    {
        $user = User::factory()->withRole('handlowiec')->create(['name' => 'Jan Handlowiec']);
        if ($operator !== null) {
            $user->forceFill(['erp_operator_ident' => $operator])->save();
        }
        UserMailAccount::query()->create([
            'user_id' => $user->id,
            'from_name' => 'Jan – SUPON',
            'from_address' => 'jan@supon.example.pl',
            'host' => 'smtp.example.pl',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'jan@supon.example.pl',
            'password' => 'tajne-haslo-123',
            'verify_peer' => true,
            'rate_per_hour' => 150,
            'copy_to_self' => false,
            'signature' => "Jan Handlowiec\ntel. 600 000 000",
            ...$account,
        ]);

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $attrs */
    protected function erpItem(string $code, float $stockTotal = 10, array $attrs = []): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->fixtureGid++,
            'code' => $code,
            'name' => 'Towar '.$code,
            'unit' => 'szt',
            'archived' => false,
            'stock_trade' => $stockTotal,
            'stock_total' => $stockTotal,
            'stock_service' => 0,
            'stock_synced_at' => '2026-09-29 02:00:00',
            'synced_at' => '2026-09-29 02:00:00',
            ...$attrs,
        ]);
    }

    protected function card(string $sku, string $name, bool $withImage = true): Product
    {
        $product = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'X', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0,
        ]);
        if ($withImage) {
            ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/'.$sku.'.jpg', 'is_primary' => true, 'sort_order' => 0]);
        }

        return $product;
    }

    /**
     * @param  list<ErpItem|Product>  $items
     * @param  array<string, mixed>  $attrs
     */
    protected function campaign(User $author, array $items = [], array $attrs = []): Campaign
    {
        $campaign = Campaign::query()->create([
            'user_id' => $author->id,
            'name' => 'Wyprzedaż',
            'subject' => 'Wyprzedaż BHP',
            'layout' => 'grid3',
            ...$attrs,
        ]);
        foreach ($items as $i => $item) {
            CampaignItem::query()->create([
                'campaign_id' => $campaign->id,
                'position' => $i,
                'erp_item_id' => $item instanceof ErpItem ? $item->id : null,
                'product_id' => $item instanceof Product ? $item->id : null,
                'promo_price_net' => 89,
            ]);
        }

        return $campaign->fresh();
    }

    /** @param  list<string>  $emails */
    protected function mailingList(User $owner, array $emails, bool $shared = false, string $name = 'Stali klienci'): MailingList
    {
        $list = MailingList::query()->create(['user_id' => $owner->id, 'name' => $name, 'is_shared' => $shared]);
        foreach ($emails as $email) {
            $contact = Contact::query()->firstOrCreate(['email' => $email], ['name' => 'Kontakt '.$email]);
            $list->contacts()->attach($contact->id, ['basis' => MailingListContact::BASIS_CUSTOMER, 'added_by' => $owner->id]);
        }

        return $list;
    }

    /**
     * @param  list<string>  $emails
     * @param  array<int, string>  $bought  id towaru XL → data ostatniego zakupu
     * @param  array<string, mixed>  $attrs
     */
    protected function customer(string $acronym, array $emails, array $bought = [], array $attrs = []): ErpCustomer
    {
        $customer = ErpCustomer::query()->create([
            'xl_gid' => $this->fixtureGid++,
            'acronym' => $acronym,
            'name' => 'Firma '.$acronym,
            'emails' => $emails,
            'archived' => false,
            'last_sale_at' => '2026-09-01',
            'sale_documents_24m' => 5,
            ...$attrs,
        ]);
        foreach ($bought as $itemId => $date) {
            ErpCustomerItem::query()->create([
                'erp_customer_id' => $customer->id, 'erp_item_id' => $itemId, 'last_sale_at' => $date, 'documents' => 1, 'quantity' => 1,
            ]);
        }

        return $customer;
    }
}
