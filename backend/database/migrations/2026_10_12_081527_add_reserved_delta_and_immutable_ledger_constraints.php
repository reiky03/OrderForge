<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->integer('reserved_delta')->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_quantity_nonzero');
            DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_change_nonzero CHECK (quantity <> 0 OR reserved_delta <> 0)');
            DB::statement("CREATE OR REPLACE FUNCTION prevent_ledger_mutation() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Ledger records are immutable'; RETURN OLD; END; \$\$");
            DB::statement('CREATE TRIGGER inventory_movements_immutable BEFORE UPDATE OR DELETE ON inventory_movements FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation()');
            DB::statement('CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER inventory_movements_change_nonzero_insert BEFORE INSERT ON inventory_movements WHEN NEW.quantity = 0 AND NEW.reserved_delta = 0 BEGIN SELECT RAISE(ABORT, 'Ledger movement must change stock'); END");
            DB::statement("CREATE TRIGGER inventory_movements_immutable_update BEFORE UPDATE ON inventory_movements BEGIN SELECT RAISE(ABORT, 'Ledger records are immutable'); END");
            DB::statement("CREATE TRIGGER inventory_movements_immutable_delete BEFORE DELETE ON inventory_movements BEGIN SELECT RAISE(ABORT, 'Ledger records are immutable'); END");
            DB::statement("CREATE TRIGGER audit_logs_immutable_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are immutable'); END");
            DB::statement("CREATE TRIGGER audit_logs_immutable_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are immutable'); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS inventory_movements_immutable ON inventory_movements');
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs');
            DB::statement('DROP FUNCTION IF EXISTS prevent_ledger_mutation()');
            DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS inventory_movements_change_nonzero');
            DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_quantity_nonzero CHECK (quantity <> 0)');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS inventory_movements_change_nonzero_insert');
            DB::statement('DROP TRIGGER IF EXISTS inventory_movements_immutable_update');
            DB::statement('DROP TRIGGER IF EXISTS inventory_movements_immutable_delete');
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_immutable_update');
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_immutable_delete');
        }

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn('reserved_delta');
        });
    }
};
