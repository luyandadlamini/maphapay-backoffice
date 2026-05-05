# Oracle Cloud Always Free Dev Host Plan

Date: 2026-05-05

## Decision

Use Oracle Cloud Always Free as the first remote development host for:

- Mifos X
- Payment Hub EE
- MaphaPay product-layer integration testing

This is a development-only environment. It is acceptable if it is imperfect, provided it is good enough to validate architecture and integration flows before moving to a paid x86 host.

## Why Oracle first

As of 2026-05-05, Oracle Cloud Always Free is the only mainstream free tier that is realistically large enough for this stack.

Official Always Free compute allowance:

- up to 2 `VM.Standard.E2.1.Micro` instances
- or OCI Ampere A1 Arm capacity equivalent to:
  - `4 OCPUs`
  - `24 GB RAM`
- `200 GB` total block/boot volume storage

Oracle also warns that:

- Always Free capacity can be unavailable
- idle Always Free instances can be reclaimed

## Important architecture caveat

The best free Oracle shape is `VM.Standard.A1.Flex`, which is Arm-based.

That matters because Mifos X upstream documents:

- Docker Compose setup tested on `Ubuntu 24.04 LTS`
- special handling for ARM situations
- explicit `linux/x86_64` image pinning in ARM examples

This means Oracle Always Free is valid for development, but not the cleanest long-term baseline.

## Recommended Oracle instance shape

Use one instance first:

- Shape: `VM.Standard.A1.Flex`
- OCPUs: `4`
- Memory: `24 GB`
- Image: `Ubuntu 24.04`
- Boot volume: `150 GB`

Rationale:

- one larger instance is simpler than splitting Mifos X and Payment Hub EE across several free instances
- 150 GB leaves room within Oracle’s 200 GB Always Free storage budget
- the smaller AMD micro shapes are too small for this stack

## Networking

Open inbound:

- `22` SSH
- `80` HTTP
- `443` HTTPS
- `6443` only if remote `kubectl` from local Mac is needed

Do not expose:

- PostgreSQL
- MySQL
- Redis
- ActiveMQ
- internal service ports

## Deployment order

1. Create Oracle tenancy and choose the correct home region
2. Launch one `VM.Standard.A1.Flex` Ubuntu 24.04 instance
3. Reserve or note the public IP
4. SSH in and run the remote bootstrap script from `maphapay-fineract-config`
5. Create a Docker SSH context from the Mac
6. Copy `mifosx-platform/postgresql` to the host and start Mifos X
7. Copy kubeconfig back to the Mac
8. Deploy Payment Hub EE Helm charts to k3s
9. Run architecture fit checks

## First go/no-go gate

Do this quickly before spending days on Oracle:

1. bootstrap the host
2. start Mifos X Docker Compose
3. confirm the Fineract and web-app containers stay healthy
4. install k3s
5. deploy one small Payment Hub EE chart

If any of the following happens, stop and move to a paid x86 host:

- required images are not available for Arm
- QEMU emulation becomes necessary for core services
- Mifos X or Payment Hub EE is unstable on the Arm host
- memory or disk pressure becomes a recurring issue

## Practical expectation

Oracle Always Free is a good zero-cost proving ground.

It is not the environment I would standardize on for the durable dev baseline if Arm compatibility becomes noisy. The likely final move is:

- paid x86 Ubuntu VM
- reuse the same remote bootstrap and deployment flow

## Exact Oracle console choices

### Compute

- Create instance
- Name: `maphapay-dev-1`
- Image: `Canonical Ubuntu 24.04`
- Shape: `VM.Standard.A1.Flex`
- Shape config: `4 OCPUs`, `24 GB` memory
- Boot volume: `150 GB`
- Networking: public subnet with public IP
- SSH keys: upload your local public key

### Storage

- do not create extra block volumes at first
- stay within the included `200 GB` total

### Security list / network security group

- allow TCP `22`, `80`, `443`
- allow TCP `6443` only if needed for remote kubectl

## Notes on Oracle account type

If Always Free capacity errors appear, Oracle’s docs say to:

- try a different availability domain
- or upgrade account type

Oracle also states that Always Free resources remain free after upgrade, with charges only for usage above the free limits.

That means a practical fallback is:

- keep using the same nominal free resources
- move the account to Pay As You Go only if capacity allocation blocks you

## Source references

- Oracle Always Free resources
- Oracle compute instance launch docs
- Mifos X platform upstream README
