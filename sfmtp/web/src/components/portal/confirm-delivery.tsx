"use client";

import { FormDialog, text } from "@/components/forms/form-dialog";
import { Input, Label, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import type { PortalShipment } from "@/lib/portal";

/** The customer confirms a delivery arrived (recorded on the shipment's trace batch). */
export function ConfirmDeliveryDialog({ partyId, shipment, onClose, onDone }: { partyId: string; shipment: PortalShipment; onClose: () => void; onDone: () => Promise<void> }) {
  return (
    <FormDialog
      title={`Confirm ${shipment.code} arrived`}
      description={`From ${shipment.farm?.name}. The farm sees who received it.`}
      submitLabel="Confirm delivery"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/customer/{party}/farms/{farm}/deliveries/{shipmentId}/confirm", {
          params: { path: { party: partyId, farm: shipment.farm!.id!, shipmentId: shipment.id! } },
          body: { received_by: text(f, "received_by"), notes: text(f, "notes") },
        });
        await onDone();
      }}
    >
      {() => (
        <>
          <div>
            <Label htmlFor="received_by">Received by</Label>
            <Input id="received_by" name="received_by" placeholder="Your name, if left empty" />
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} placeholder="Anything missing or damaged?" />
          </div>
        </>
      )}
    </FormDialog>
  );
}
